<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Exception\ApiProblem;
use App\Repository\EntryRepository;
use App\Service\RateLimitGuard;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Baut aus einer Liste von Format-IDs ein Bundle der veröffentlichten
 * Payloads: { gesturaBundle: 1, entries: [ <payload>, … ] }. Speist den
 * Sammelkorb-Datei-Download der Website (ein Request statt N). Unbekannte
 * oder nicht-veröffentlichte IDs werden stillschweigend ausgelassen; der
 * Client gleicht angefragte gegen gelieferte IDs (entries[].id) selbst ab.
 *
 * POST mit JSON-Body statt GET, weil große Körbe als Query-String Apaches
 * LimitRequestLine überschreiten könnten. Öffentliche cookielose API mit
 * "*"-CORS (CorsSubscriber deckt den Preflight). Kein Install-Zähler.
 */
final class BundleController
{
    private const MAX_IDS = 200;

    #[Route('/api/v1/bundle', methods: ['POST'])]
    public function __invoke(
        Request $request,
        EntryRepository $entries,
        RateLimitGuard $guard,
        RateLimiterFactoryInterface $bundleLimiter,
    ): JsonResponse {
        $guard->consume($bundleLimiter, $request->getClientIp() ?? 'unknown');

        $body = json_decode($request->getContent(), true);
        if (!is_array($body) || !array_key_exists('ids', $body)) {
            throw new ApiProblem(400, 'Invalid request body');
        }
        $ids = $body['ids'];
        if (!is_array($ids) || $ids === []) {
            throw new ApiProblem(400, 'ids must be a non-empty array');
        }

        $clean = [];
        $seen = [];
        foreach ($ids as $id) {
            if (!is_string($id)) {
                throw new ApiProblem(400, 'ids must be strings');
            }
            $id = trim($id);
            if ($id !== '' && !isset($seen[$id])) {
                $seen[$id] = true;
                $clean[] = $id;
            }
        }
        if (count($clean) > self::MAX_IDS) {
            throw new ApiProblem(400, 'Too many ids (max ' . self::MAX_IDS . ')');
        }
        if ($clean === []) {
            return new JsonResponse(['gesturaBundle' => 1, 'entries' => []]);
        }

        $byId = [];
        foreach ($entries->findPublishedByFormatIds($clean) as $entry) {
            $byId[$entry->formatId] = $entry->currentVersion->payload;
        }

        $out = [];
        foreach ($clean as $id) {
            if (isset($byId[$id])) {
                $out[] = $byId[$id];
            }
        }

        return new JsonResponse(['gesturaBundle' => 1, 'entries' => $out]);
    }
}
