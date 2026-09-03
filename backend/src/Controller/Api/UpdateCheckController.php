<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Api\ApiLevel;
use App\Exception\ApiProblem;
use App\Repository\EntryRepository;
use App\Service\RateLimitGuard;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Update-Check der Extension (Vertrag docs/gestura-eu-api.md, Abschnitt
 * »Update check«, apiLevel 2). Anonym, cookielos, öffentliche »*«-CORS-API.
 *
 * Der Client fragt mit (id, version|null)-Paaren; geantwortet wird nur für
 * Einträge, zu denen es etwas zu sagen gibt: eine numerisch neuere Version,
 * eine Abkündigung oder beides. »version: null« heißt »sag mir die aktuelle
 * Version«. Der Typ (menu|engine) reist bewusst nur in der Antwort – der
 * Client prüft ihn gegen seine lokale Kenntnis, der Server braucht ihn nicht
 * und erfährt nichts über die Einrichtung des Nutzers.
 *
 * Die Download-URL wird aus Schema und Host des Requests gebildet und liegt
 * damit auf der antwortenden Origin – der Client verwirft alles andere.
 * Fehlerhafte Einzelposten werden still übersprungen (der Check bleibt
 * nutzbar); nur der Umschlag wird streng geprüft.
 */
final class UpdateCheckController
{
    private const MAX_ENTRIES = 200;
    // Kennungsmuster aus dem Vertrag (Abschnitt »Bridge«, Limits) – identisch zu ID_RE der Extension.
    private const ID_RE = '/^[a-zA-Z0-9]([a-zA-Z0-9._-]*[a-zA-Z0-9])?$/';
    private const ID_MAX_LENGTH = 128;
    // Dieselbe SEMVER_RE wie im Austauschformat: nur numerische Tripel sind vergleichbar.
    private const SEMVER_RE = '/^\d{1,5}\.\d{1,5}\.\d{1,5}$/';
    // Der Client kürzt changelog ohnehin auf 1000 Zeichen (Vertrag) – alles
    // darüber ist reine Verschwendung im Wire-Format.
    private const CHANGELOG_CLIENT_MAX_CHARS = 1000;
    // Byte-Budget für alle changelog-Felder zusammen: 200 KiB Puffer, deutlich
    // unter dem 256-KiB-Cap, den der Client als Ganzes verwirft. Symfonys
    // JsonResponse kodiert ohne JSON_UNESCAPED_UNICODE, jedes Nicht-ASCII-
    // Zeichen (Umlaute, CJK) kostet dadurch bis zu 6 Byte (ä-Escape) statt
    // 1–3 UTF-8-Byte – gemessen: 2000 deutsche wie auch 2000 CJK-Zeichen
    // kodieren zu 12002 Byte. Nach der 1000-Zeichen-Kürzung bleiben im
    // ungünstigsten Fall 1000 * 6 + 2 (Anführungszeichen) = 6002 Byte pro
    // changelog. Die verbleibenden ~56 KiB bis 256 KiB decken Hülle plus bis
    // zu 200 Elemente ganz ohne changelog (id, type, version, url,
    // deprecated, successor) mit reichlich Reserve ab.
    private const CHANGELOG_BUDGET_BYTES = 200 * 1024;

    #[Route('/api/v1/updates', methods: ['POST'])]
    public function __invoke(
        Request $request,
        EntryRepository $entries,
        RateLimitGuard $guard,
        RateLimiterFactoryInterface $updateCheckLimiter,
    ): JsonResponse {
        $guard->consume($updateCheckLimiter, $request->getClientIp() ?? 'unknown');

        try {
            $body = json_decode($request->getContent(), true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ApiProblem(400, 'Invalid JSON body');
        }

        // apiLevel wird bewusst nicht erzwungen: der Vertrag verlangt Toleranz
        // gegenüber älteren und künftigen Clients.
        $list = \is_array($body) ? ($body['entries'] ?? null) : null;
        if (!\is_array($list)) {
            throw new ApiProblem(400, 'entries must be a list');
        }
        if (\count($list) > self::MAX_ENTRIES) {
            throw new ApiProblem(400, 'entries must contain at most ' . self::MAX_ENTRIES . ' items');
        }

        // Schritt 1: gültige (id, version|null)-Paare einsammeln, Doppelte
        // zusammenfassen (der erste Posten gewinnt), Fehlerhaftes überspringen.
        /** @var array<string, ?string> $wanted */
        $wanted = [];
        foreach ($list as $item) {
            if (!\is_array($item) || !\array_key_exists('version', $item)) {
                continue;
            }
            $id = $item['id'] ?? null;
            $version = $item['version'];
            if (!\is_string($id) || \strlen($id) > self::ID_MAX_LENGTH || !preg_match(self::ID_RE, $id)) {
                continue;
            }
            if ($version !== null && (!\is_string($version) || !preg_match(self::SEMVER_RE, $version))) {
                continue;
            }
            if (\array_key_exists($id, $wanted)) {
                continue;
            }
            $wanted[$id] = $version;
        }

        // Schritt 2: ein Batch-Lookup (fetch-joined currentVersion, kein N+1).
        // strval: PHP macht rein numerische Array-Schlüssel zu int.
        $byFormatId = [];
        foreach ($entries->findPublishedByFormatIds(array_map(strval(...), array_keys($wanted))) as $entry) {
            $byFormatId[$entry->formatId] = $entry;
        }

        // Schritt 3: Antwort in Eingabereihenfolge aufbauen.
        $base = $request->getSchemeAndHttpHost();
        $updates = [];
        $changelogBudget = self::CHANGELOG_BUDGET_BYTES;
        $changelogBudgetExhausted = false;
        foreach ($wanted as $id => $clientVersion) {
            $entry = $byFormatId[(string) $id] ?? null;
            $current = $entry?->currentVersion;
            if ($entry === null || $current === null) {
                continue; // unbekannt, nicht veröffentlicht oder (defensiv) ohne Version
            }
            $newer = $clientVersion === null || version_compare($current->semver, $clientVersion, '>');
            if (!$newer && !$entry->deprecated) {
                continue; // aktuell oder Handimport einer neueren Fassung – nichts zu sagen
            }

            $changelog = $current->changelog === null
                ? null
                : mb_substr($current->changelog, 0, self::CHANGELOG_CLIENT_MAX_CHARS);
            // Ein Element ist tragend (nur so erfährt der Nutzer vom Update),
            // ein changelog ist Kür und laut Vertrag optional – daher wird nie
            // ein Element verworfen, sondern höchstens sein changelog auf null
            // gesetzt, sobald das Byte-Budget aufgebraucht ist.
            if ($changelog !== null) {
                if ($changelogBudgetExhausted) {
                    $changelog = null;
                } else {
                    $cost = \strlen(json_encode($changelog, JSON_THROW_ON_ERROR));
                    if ($cost > $changelogBudget) {
                        $changelogBudgetExhausted = true;
                        $changelog = null;
                    } else {
                        $changelogBudget -= $cost;
                    }
                }
            }

            $updates[] = [
                'id' => $entry->formatId,
                'type' => $entry->type->value,
                'version' => $current->semver,
                'url' => sprintf('%s/api/v1/entries/%s/versions/%s', $base, rawurlencode($entry->formatId), $current->semver),
                'changelog' => $changelog,
                'deprecated' => $entry->deprecated,
                'successor' => $entry->successorFormatId,
            ];
        }

        return new JsonResponse(['apiLevel' => ApiLevel::IMPLEMENTED, 'updates' => $updates]);
    }
}
