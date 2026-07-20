<?php

declare(strict_types=1);

namespace App\Service;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class ExchangeValidator
{
    private const BLOB_MAX = 102400;

    private const ALLOWED_ACTIONS = [
        'none', 'openCustomUrl', 'searchLink', 'back', 'forward', 'refresh',
        'newTab', 'scrollUp', 'scrollDown', 'scrollToTop', 'scrollToBottom',
    ];

    private string $menuSchemaJson;
    private string $engineSchemaJson;

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
        }

        if ($errors !== []) {
            return ValidationResult::fail($type, array_values(array_unique($errors)));
        }

        /** @var array<string, mixed> $payload */
        $payload = json_decode($rawJson, true, 32, JSON_THROW_ON_ERROR);

        return new ValidationResult(true, $type, [], $payload);
    }

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

    private function isHttpsUrl(mixed $value): bool
    {
        if (!\is_string($value) || $value === '') {
            return false;
        }
        $parts = parse_url($value);

        return \is_array($parts) && ($parts['scheme'] ?? null) === 'https' && ($parts['host'] ?? '') !== '';
    }
}
