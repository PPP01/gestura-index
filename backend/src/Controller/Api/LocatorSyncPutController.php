<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Api\SyncContract;
use App\Exception\SyncProblem;
use App\Service\LocatorSyncService;
use App\Service\RateLimitGuard;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * PUT /api/v1/sync/state – einen Stand anlegen oder ersetzen (Vertrag,
 * apiLevel 3).
 *
 * Die drei Fälle von »basePayloadHash« sind tragend: der Drei-Wege-Merge der
 * Extension setzt alles darauf. Ein 412, das trotzdem schreibt, oder ein
 * Vergleich gegen etwas anderes als den gespeicherten Payload-Envelope,
 * überschreibt dem Nutzer stillschweigend die Einstellungen auf dem anderen
 * Rechner.
 *
 * Ein payloadHash wird NICHT zurückgegeben – ihn kennt nur der hochladende
 * Client, weil jede Verschlüsselung eine frische IV nutzt.
 */
final class LocatorSyncPutController
{
    #[Route('/api/v1/sync/state', methods: ['PUT'])]
    public function __invoke(
        Request $request,
        LocatorSyncService $sync,
        RateLimitGuard $guard,
        RateLimiterFactoryInterface $syncV1Limiter,
        RateLimiterFactoryInterface $syncV1BytesLimiter,
    ): JsonResponse {
        $ip = $request->getClientIp() ?? 'unknown';
        $guard->consume($syncV1Limiter, $ip, problem: SyncProblem::class);

        $body = SyncRequest::parse($request);
        $locatorHash = SyncRequest::locatorHash($body);
        $stateId = SyncRequest::stateId($body, required: true);
        $meta = SyncRequest::envelope($body, 'meta', SyncContract::MAX_META_BYTES);
        $payload = SyncRequest::envelope($body, 'payload', SyncContract::MAX_PAYLOAD_BYTES);
        $baseHash = SyncRequest::basePayloadHash($body);

        // Das mengenbasierte Limit erst NACH der Formprüfung: ein kaputter
        // Request soll kein Schreibbudget verbrauchen. Gezählt wird in
        // angefangenen KiB – das ist der Hebel, den der Vertrag als ersten
        // gegen Missbrauch nennt.
        $guard->consume($syncV1BytesLimiter, $ip, (int) ceil((\strlen($meta) + \strlen($payload)) / 1024), SyncProblem::class);

        $state = $sync->write($locatorHash, $stateId, $meta, $payload, $baseHash);

        return new JsonResponse([
            'stateId' => $state->stateId,
            'updatedAt' => $state->updatedAt->format(\DateTimeInterface::ATOM),
            'size' => $state->sizeBytes,
        ]);
    }
}
