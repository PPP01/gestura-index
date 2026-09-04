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
        SyncStateRepository $states,
        EntityManagerInterface $em,
        RateLimitGuard $guard,
        RateLimiterFactoryInterface $syncV1Limiter,
    ): JsonResponse {
        $guard->consume($syncV1Limiter, $request->getClientIp() ?? 'unknown', problem: SyncProblem::class);

        $body = SyncRequest::parse($request);
        $locatorHash = SyncRequest::locatorHash($body);
        $stateId = SyncRequest::stateId($body, required: true);

        $state = $states->findOneByLocatorAndState($locatorHash, $stateId);
        if ($state === null) {
            throw SyncProblem::notFound();
        }

        // Lesen frischt die Aufbewahrungsfrist auf, rührt updatedAt nicht an.
        $state->touchAccess();
        $em->flush();

        return new JsonResponse([
            'stateId' => $state->stateId,
            'updatedAt' => $state->updatedAt->format(\DateTimeInterface::ATOM),
            'payload' => $state->payload,
        ]);
    }
}
