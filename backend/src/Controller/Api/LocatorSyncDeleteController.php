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
 * POST /api/v1/sync/delete – einen Stand oder alle Stände eines Locators
 * löschen (Vertrag, apiLevel 3).
 *
 * Bewusst BEDINGUNGSLOS und ohne Schreib-Token: Löschen passiert hinter
 * einer Bestätigung, und anders als ein stilles Überschreiben sieht der
 * Nutzer dabei zu (so begründet es der Vertrag).
 *
 * Fehlt »stateId«, fällt alles unter dem Locator. Das ist die einzige Stelle,
 * an der ein Nutzer seine Daten selbst wieder loswird – der Server kann sie
 * ihm nicht zuordnen, also muss der Locator genügen.
 */
final class LocatorSyncDeleteController
{
    #[Route('/api/v1/sync/delete', methods: ['POST'])]
    public function __invoke(
        Request $request,
        LocatorSyncService $sync,
        RateLimitGuard $guard,
        RateLimiterFactoryInterface $syncV1Limiter,
    ): JsonResponse {
        [$body, $locatorHash] = LocatorSyncRequest::open($request, $guard, $syncV1Limiter);
        $stateId = LocatorSyncRequest::optionalStateId($body);

        $deleted = $stateId === null
            ? $sync->deleteAll($locatorHash)
            : $sync->delete($locatorHash, $stateId);

        return new JsonResponse(['deleted' => $deleted]);
    }
}
