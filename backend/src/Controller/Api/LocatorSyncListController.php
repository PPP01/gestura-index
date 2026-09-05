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
 * POST /api/v1/sync/list – die Stände eines Locators (Vertrag, apiLevel 3).
 *
 * Geliefert werden stateId, size, updatedAt und der META-Blob, nie der
 * payload: genau dafür ist der Stand in zwei Chiffrate geteilt. Ein zweiter
 * Browser entschlüsselt die Metas, zeigt »Arbeit – geändert am 3. September«
 * und lädt eine Nutzlast erst herunter, wenn der Nutzer eine auswählt.
 *
 * Ein unbekannter Locator ist kein Fehler, sondern eine leere Liste – der
 * Vertrag kennt für diesen Fall keinen Code, und ein 404 verriete außerdem,
 * welche Locators belegt sind.
 */
final class LocatorSyncListController
{
    #[Route('/api/v1/sync/list', methods: ['POST'])]
    public function __invoke(
        Request $request,
        LocatorSyncService $sync,
        RateLimitGuard $guard,
        RateLimiterFactoryInterface $syncV1Limiter,
    ): JsonResponse {
        [, $locatorHash] = LocatorSyncRequest::open($request, $guard, $syncV1Limiter);

        return new JsonResponse(['states' => $sync->list($locatorHash)]);
    }
}
