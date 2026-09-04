<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Exception\SyncProblem;
use App\Repository\SyncStateRepository;
use App\Service\RateLimitGuard;
use Doctrine\ORM\EntityManagerInterface;
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
        SyncStateRepository $states,
        EntityManagerInterface $em,
        RateLimitGuard $guard,
        RateLimiterFactoryInterface $syncV1Limiter,
    ): JsonResponse {
        $guard->consume($syncV1Limiter, $request->getClientIp() ?? 'unknown', problem: SyncProblem::class);

        $body = SyncRequest::parse($request);
        $locatorHash = SyncRequest::locatorHash($body);
        $stateId = SyncRequest::stateId($body, required: false);

        if ($stateId === null) {
            return new JsonResponse(['deleted' => $states->deleteByLocator($locatorHash)]);
        }

        $state = $states->findOneByLocatorAndState($locatorHash, $stateId);
        if ($state === null) {
            throw SyncProblem::notFound();
        }
        $em->remove($state);
        $em->flush();

        return new JsonResponse(['deleted' => 1]);
    }
}
