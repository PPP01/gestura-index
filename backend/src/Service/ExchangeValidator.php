<?php

declare(strict_types=1);

namespace App\Service;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Validiert eingereichte Payloads gegen das Gestura-Austauschformat (exchange-schema.json)
 * und erzwingt Zusatzregeln, die JSON-Schema nicht ausdrücken kann (eindeutige Item-IDs,
 * Aktions-Whitelist, HTTPS-Pflicht für URLs). Liefert ein ValidationResult zurück.
 */
final class ExchangeValidator
{
    private const BLOB_MAX = 102400;

    private const ALLOWED_ACTIONS = [
        'none', 'openCustomUrl', 'searchLink', 'back', 'forward', 'refresh',
        'newTab', 'scrollUp', 'scrollDown', 'scrollToTop', 'scrollToBottom',
    ];

    private string $menuSchemaJson;
    private string $engineSchemaJson;

    /**
     * Lädt exchange-schema.json und baut daraus zwei typspezifische Teilschemata
     * (menu / engine), um bei der Validierung präzise Fehlermeldungen ohne
     * »required properties fehlen«-Rauschen des jeweils anderen Typs zu liefern.
     */
    public function __construct(
        #[Autowire('%kernel.project_dir%/../schema/exchange-schema.json')]
        string $schemaPath,
    ) {
        $schemaJson = file_get_contents($schemaPath)
            ?: throw new \RuntimeException('exchange-schema.json nicht lesbar: ' . $schemaPath);

        // Das Rohschema ist ein oneOf(menu, engine): Würde $data unverändert
        // dagegen validiert, tauchen bei jedem ungültigen Payload zusätzlich
        // die "required properties fehlen"-Fehler des jeweils ANDEREN Typs
        // auf, obwohl detectType() den Typ längst kennt. Da errors der
        // öffentliche Vertrag gegenüber Einreichern ist, bauen wir hier zwei
        // typspezifische Teilschemata und validieren gezielt gegen das
        // passende — $ref + $defs teilen sich die Definitionen aus dem
        // unveränderten Original.
        $schema = json_decode($schemaJson);
        $this->menuSchemaJson = json_encode(['$ref' => '#/$defs/menu', '$defs' => $schema->{'$defs'}]);
        $this->engineSchemaJson = json_encode(['$ref' => '#/$defs/engine', '$defs' => $schema->{'$defs'}]);
    }

    /**
     * Validiert rawJson vollständig: Größenlimit, JSON-Parsing, Typ-Erkennung,
     * Schema-Prüfung und anschließend typspezifische Zusatzregeln.
     * Gibt ValidationResult::fail() bei jedem Fehler zurück, oder ein
     * ValidationResult mit dekodiertem Payload bei Erfolg.
     */
    public function validate(string $rawJson): ValidationResult
    {
        if (\strlen($rawJson) > self::BLOB_MAX) {
            return ValidationResult::fail(null, ['tooLarge']);
        }

        try {
            $data = json_decode($rawJson, false, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return ValidationResult::fail(null, ['invalidJson']);
        }

        $type = $this->detectType($data);
        if ($type === null) {
            return ValidationResult::fail(null, ['notGesturaFormat']);
        }

        $errors = [];

        $typeSchemaJson = $type === 'menu' ? $this->menuSchemaJson : $this->engineSchemaJson;
        $result = (new Validator())->validate($data, $typeSchemaJson);
        if (!$result->isValid()) {
            foreach (array_keys((new ErrorFormatter())->format($result->error())) as $pointer) {
                $errors[] = 'schema:' . $pointer;
            }
        }

        if ($type === 'menu') {
            $this->applyMenuRules($data, $errors);
        } else {
            $this->applyEngineRules($data, $errors);
        }

        if ($errors !== []) {
            return ValidationResult::fail($type, array_values(array_unique($errors)));
        }

        /** @var array<string, mixed> $payload */
        $payload = json_decode($rawJson, true, 32, JSON_THROW_ON_ERROR);

        return new ValidationResult(true, $type, [], $payload);
    }

    /**
     * Erkennt den Payload-Typ anhand der Sentinel-Properties »gesturaMenu« und
     * »gesturaEngine«. Gibt null zurück, wenn keiner der Typen erkennbar ist.
     */
    private function detectType(mixed $data): ?string
    {
        if (!\is_object($data)) {
            return null;
        }
        if (\is_int($data->gesturaMenu ?? null)) {
            return 'menu';
        }
        if (\is_int($data->gesturaEngine ?? null)) {
            return 'engine';
        }

        return null;
    }

    /**
     * Zusatzregeln, die das JSON-Schema nicht ausdrücken kann — portiert aus
     * js/menu-exchange.js (validateMenu): eindeutige Item-IDs, Nicht-Separator
     * braucht Whitelist-Aktion, openCustomUrl/searchLink brauchen https-Ziele.
     *
     * @param list<string> $errors
     */
    private function applyMenuRules(object $menu, array &$errors): void
    {
        // homepage ist optional, muss aber – wenn gesetzt – eine echte HTTPS-URL
        // sein (JS-Validator validateMenu). Das Schema-`pattern: ^https://` allein
        // ließe host-lose Werte wie "https://" durch.
        if (isset($menu->homepage) && !$this->isHttpsUrl($menu->homepage)) {
            $errors[] = 'homepage';
        }

        $items = $menu->items ?? null;
        if (!\is_array($items)) {
            return; // Struktur bereits vom Schema bemängelt
        }

        $seen = [];
        foreach ($items as $item) {
            if (!\is_object($item) || !\is_string($item->id ?? null)) {
                continue; // Struktur bereits vom Schema bemängelt
            }
            if (isset($seen[$item->id])) {
                $errors[] = 'duplicateItemId';
                continue;
            }
            $seen[$item->id] = true;

            if (($item->type ?? null) === 'separator') {
                continue;
            }
            if (!\in_array($item->action ?? null, self::ALLOWED_ACTIONS, true)) {
                $errors[] = 'itemAction';
                continue;
            }
            if ($item->action === 'openCustomUrl' && !$this->isHttpsUrl($item->customUrl ?? null)) {
                $errors[] = 'itemUrl';
            }
            if ($item->action === 'searchLink') {
                $hasEngine = \is_string($item->engineId ?? null) && ($item->engineId !== '');
                $hasUrl = $this->isHttpsUrl($item->url ?? null);
                if (!$hasEngine && !$hasUrl) {
                    $errors[] = 'itemSearch';
                }
            }
        }
    }

    /**
     * Zusatzregel für Engines, portiert aus js/menu-exchange.js (validateEngine):
     * die Ziel-URL muss eine echte HTTPS-URL mit Host sein. Das Schema prüft nur
     * den `^https://`-Präfix und lässt host-lose Werte wie "https://" durch;
     * der autoritative Client lehnt sie via new URL() ab — das Backend muss
     * identisch validieren.
     *
     * @param list<string> $errors
     */
    private function applyEngineRules(object $engine, array &$errors): void
    {
        if (!$this->isHttpsUrl($engine->url ?? null)) {
            $errors[] = 'url';
        }
    }

    /**
     * Prüft, ob value eine syntaktisch gültige HTTPS-URL mit nichtleerem Host ist.
     */
    private function isHttpsUrl(mixed $value): bool
    {
        if (!\is_string($value) || $value === '') {
            return false;
        }
        // Roh-Whitespace ist in keiner gültigen URL erlaubt; parse_url akzeptiert
        // ihn aber als Host (z.B. "https:// not a url" → host " not a url"),
        // während der autoritative new URL()-Check des Clients ihn verwirft.
        if (preg_match('/\s/', $value) === 1) {
            return false;
        }
        $parts = parse_url($value);

        return \is_array($parts) && ($parts['scheme'] ?? null) === 'https' && ($parts['host'] ?? '') !== '';
    }
}
