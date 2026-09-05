<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Api\LocatorSyncRequest;
use App\Service\LocatorSyncService;
use App\Service\RateLimitGuard;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * POST /api/v1/sync/get – die Nutzlast eines Standes (Vertrag, apiLevel 3).
 *
 * POST statt GET, weil der Locator ein Bearer-Token ist und im BODY reisen
 * muss: in der URL stünde er in jedem Zugriffslog und in jedem Proxy-Cache.
 *
 * Geliefert wird der payload-Envelope; der Client prüft ihn gegen den
 * payloadHash aus dem Meta-Blob, den er beim Listen entschlüsselt hat. Meta
 * und payload eines Standes werden deshalb immer gemeinsam ersetzt – ein
 * gemischtes Paar lässt seinen Download fehlschlagen.
 */
final class LocatorSyncGetController
{
    #[Route('/api/v1/sync/get', methods: ['POST'])]
    public function __invoke(
        Request $request,
        LocatorSyncService $sync,
        RateLimitGuard $guard,
        RateLimiterFactoryInterface $syncV1Limiter,
    ): JsonResponse {
        [$body, $locatorHash] = LocatorSyncRequest::open($request, $guard, $syncV1Limiter);

        $state = $sync->read($locatorHash, LocatorSyncRequest::stateId($body));

        return new JsonResponse([
            'stateId' => $state->stateId,
            'updatedAt' => $state->updatedAt->format(\DateTimeInterface::ATOM),
            'payload' => $state->payload,
        ]);
    }
}
