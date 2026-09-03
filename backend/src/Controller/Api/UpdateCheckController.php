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
            $updates[] = [
                'id' => $entry->formatId,
                'type' => $entry->type->value,
                'version' => $current->semver,
                'url' => sprintf('%s/api/v1/entries/%s/versions/%s', $base, rawurlencode($entry->formatId), $current->semver),
                'changelog' => $current->changelog,
                'deprecated' => $entry->deprecated,
                'successor' => $entry->successorFormatId,
            ];
        }

        return new JsonResponse(['apiLevel' => ApiLevel::IMPLEMENTED, 'updates' => $updates]);
    }
}
