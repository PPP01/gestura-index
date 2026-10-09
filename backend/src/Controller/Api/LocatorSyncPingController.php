<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Api\SyncContract;
use App\Exception\SyncProblem;
use App\Service\RateLimitGuard;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * GET /api/v1/sync/ping – Erreichbarkeits- und Feature-Check (Vertrag,
 * Extension-Instruktion 2026-10-09).
 *
 * Kein Body, kein Locator, kein apiLevel. Der Ping sagt nichts über einen
 * Nutzer und braucht nichts von ihm. Ruft LocatorSyncRequest::open() bewusst
 * NICHT auf – der Endpunkt hat kein apiLevel und keinen Locator.
 *
 * »ok« heißt: der Sync-Dienst funktioniert, nicht nur der Webserver lebt.
 * Die DB wird per SELECT 1 berührt; scheitert das, meldet der Ping
 * 503 { "error": "unavailable" }.
 *
 * Einzelroute mit optionalem Slash-Parameter – ohne ihn erzeugt
 * Symfonys Router für GET-Routen einen 301, der bei
 * redirect:"error" als Netzfehler ankäme (E9). Zwei getrennte
 * Routen funktionieren nicht (empirisch belegt, s. E9).
 */
final class LocatorSyncPingController
{
    #[Route('/api/v1/sync/ping{slash}',
        name: 'api_v1_sync_ping',
        requirements: ['slash' => '/?'],
        defaults: ['slash' => ''],
        methods: ['GET'],
    )]
    public function __invoke(
        Request $request,
        Connection $connection,
        RateLimitGuard $guard,
        RateLimiterFactoryInterface $syncPingLimiter,
    ): JsonResponse {
        $guard->consume(
            $syncPingLimiter,
            $request->getClientIp() ?? 'unknown',
            onExhausted: SyncProblem::rateLimited(...),
        );

        try {
            $connection->executeQuery('SELECT 1');
        } catch (\Throwable) {
            throw SyncProblem::unavailable();
        }

        $response = new JsonResponse([
            'status' => 'ok',
            'features' => SyncContract::FEATURES,
        ]);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
