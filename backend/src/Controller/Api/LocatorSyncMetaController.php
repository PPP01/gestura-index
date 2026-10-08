<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Api\LocatorSyncRequest;
use App\Api\SyncContract;
use App\Exception\SyncProblem;
use App\Service\LocatorSyncService;
use App\Service\RateLimitGuard;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * POST /api/v1/sync/meta – nur den Meta-Blob ersetzen, Payload unangetastet
 * (Vertrag, Abschnitt »POST /api/v1/sync/meta«). Das Umbenennen soll nicht
 * den vollen Payload (bis 512 KiB) neu hochladen müssen.
 *
 * basePayloadHash ist PFLICHT – anders als beim PUT, wo sein Fehlen
 * bedingungsloses Schreiben bedeutet: sonst überdeckte ein Umbenennen einen
 * zwischenzeitlich überschriebenen Stand. Zur Frist siehe SyncState::replaceMeta().
 */
final class LocatorSyncMetaController
{
    #[Route('/api/v1/sync/meta', methods: ['POST'])]
    public function __invoke(
        Request $request,
        LocatorSyncService $sync,
        RateLimitGuard $guard,
        RateLimiterFactoryInterface $syncV1Limiter,
        RateLimiterFactoryInterface $syncV1BytesLimiter,
    ): JsonResponse {
        [$body, $locatorHash] = LocatorSyncRequest::open($request, $guard, $syncV1Limiter);
        $stateId = LocatorSyncRequest::stateId($body);
        $meta = LocatorSyncRequest::envelope($body, 'meta', SyncContract::MAX_META_BYTES);
        $baseHash = LocatorSyncRequest::requiredBasePayloadHash($body);

        // Das mengenbasierte Limit NACH der Formprüfung, aber VOR dem Lookup:
        // 400- und 413-Fehler kosten kein Byte-Budget; 404- und 412-Fehler
        // (abgelehnte Schreibversuche) hingegen schon – so steht es im Vertrag
        // (»the bytes sent«), und es entspricht dem Verhalten des PUT.
        $guard->consume(
            $syncV1BytesLimiter,
            $request->getClientIp() ?? 'unknown',
            SyncContract::tokensFor(\strlen($meta)),
            SyncProblem::rateLimited(...),
        );

        $state = $sync->replaceMeta($locatorHash, $stateId, $meta, $baseHash);

        return new JsonResponse([
            'stateId' => $state->stateId,
            'updatedAt' => SyncContract::formatTimestamp($state->updatedAt),
            'size' => $state->sizeBytes,
        ]);
    }
}
