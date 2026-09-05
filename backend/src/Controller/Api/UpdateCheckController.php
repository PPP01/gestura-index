<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Api\ApiLevel;
use App\Api\ExchangeFormat;
use App\Exception\ApiProblem;
use App\Repository\EntryRepository;
use App\Service\RateLimitGuard;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

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
 * Die Download-URL erzeugt der Router als absolute URL aus Schema und Host
 * des Requests; sie liegt damit auf der antwortenden Origin – der Client
 * verwirft alles andere.
 * Fehlerhafte Einzelposten werden still übersprungen (der Check bleibt
 * nutzbar); nur der Umschlag wird streng geprüft.
 */
final class UpdateCheckController
{
    private const MAX_ENTRIES = 200;
    // Der Client kürzt changelog ohnehin auf 1000 Zeichen (Vertrag) – alles
    // darüber ist reine Verschwendung im Wire-Format.
    private const CHANGELOG_CLIENT_MAX_CHARS = 1000;
    // Der Client verwirft jede Antwort über 256 KiB als GANZES (Vertrag,
    // »Validation on the client«): dann startet nicht einmal sein
    // 24-Stunden-Fenster, und er fragt bei jedem Öffnen der Einstellungen neu.
    public const CLIENT_RESPONSE_CAP_BYTES = 256 * 1024;
    // Byte-Budget für alle changelog-Felder zusammen. Gemessen wird mit
    // JsonResponse::DEFAULT_ENCODING_OPTIONS – NICHT mit den json_encode-
    // Standardflags –, weil genau diese Flags auch die Antwort kodieren:
    // JSON_HEX_QUOT|APOS|AMP|TAG eskalieren " ' & < > zu 6-Byte-Escapes, wie
    // es für Nicht-ASCII ohnehin geschieht. Mit den Standardflags gemessen
    // wäre gewöhnlicher englischer Text (Apostrophe, »&«) unterschätzt, und
    // die echte Antwort wüchse über den Cap, ohne dass das Budget anschlägt.
    // Die Marge zum Cap neben dem größtmöglichen Elemente-Skelett (200
    // Elemente, id und successor je 128 Zeichen, jeder changelog maximal
    // teuer) sichert UpdateCheckTest::testWorstCaseResponseStaysUnderTheClientCap
    // gegen den ECHTEN Response-Body ab – nicht ein Rechenkommentar.
    private const CHANGELOG_BUDGET_BYTES = 100 * 1024;

    #[Route('/api/v1/updates', methods: ['POST'])]
    public function __invoke(
        Request $request,
        EntryRepository $entries,
        RateLimitGuard $guard,
        RateLimiterFactoryInterface $updateCheckLimiter,
        UrlGeneratorInterface $urlGenerator,
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
            if (!\is_string($id) || \strlen($id) > ExchangeFormat::ID_MAX_LENGTH || !preg_match(ExchangeFormat::ID_REGEX, $id)) {
                continue;
            }
            if ($version !== null && (!\is_string($version) || !preg_match(ExchangeFormat::SEMVER_REGEX, $version))) {
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
        $updates = [];
        $changelogBudget = self::CHANGELOG_BUDGET_BYTES;
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
            // gesetzt, sobald das Byte-Budget aufgebraucht ist. Passt einer
            // nicht mehr, fällt das Budget auf 0 und bleibt dort: jeder kodierte
            // changelog kostet mindestens 2 Byte, also verstummen alle weiteren.
            if ($changelog !== null) {
                // Dieselben Flags wie die Antwort selbst (siehe Kommentar an
                // CHANGELOG_BUDGET_BYTES) – sonst misst das Budget etwas
                // anderes, als tatsächlich auf die Leitung geht.
                $cost = \strlen(json_encode($changelog, JsonResponse::DEFAULT_ENCODING_OPTIONS | JSON_THROW_ON_ERROR));
                if ($cost > $changelogBudget) {
                    $changelog = null;
                    $changelogBudget = 0;
                } else {
                    $changelogBudget -= $cost;
                }
            }

            $updates[] = [
                'id' => $entry->formatId,
                'type' => $entry->type->value,
                'version' => $current->semver,
                'url' => $urlGenerator->generate(
                    VersionDownloadController::ROUTE,
                    ['formatId' => $entry->formatId, 'semver' => $current->semver],
                    UrlGeneratorInterface::ABSOLUTE_URL,
                ),
                'changelog' => $changelog,
                'deprecated' => $entry->deprecated,
                'successor' => $entry->successorFormatId,
            ];
        }

        return new JsonResponse(['apiLevel' => ApiLevel::IMPLEMENTED, 'updates' => $updates]);
    }
}
