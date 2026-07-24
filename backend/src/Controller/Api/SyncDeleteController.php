<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\SyncBlob;
use App\Exception\ApiProblem;
use App\Repository\SyncBlobRepository;
use App\Service\AccountResolver;
use App\Service\RateLimitGuard;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Leert einen Sync-Slot (Teil des Löschrechts). Der Versionszähler beginnt
 * danach wieder bei 0 (Erst-Upload mit baseVersion 0).
 */
final class SyncDeleteController
{
    #[Route('/api/account/sync/{collection}', methods: ['DELETE'])]
    public function __invoke(
        string $collection,
        Request $request,
        AccountResolver $resolver,
        SyncBlobRepository $blobs,
        EntityManagerInterface $em,
        RateLimitGuard $guard,
        RateLimiterFactoryInterface $syncWriteLimiter,
    ): Response {
        $account = $resolver->requireAccount($request);
        if (!\in_array($collection, SyncBlob::COLLECTIONS, true)) {
            throw new ApiProblem(400, 'Unknown collection');
        }
        $guard->consume($syncWriteLimiter, $request->getClientIp() ?? 'unknown');

        $blob = $blobs->findOneBy(['account' => $account, 'collection' => $collection])
            ?? throw new ApiProblem(404, 'No sync data for this collection');
        $em->remove($blob);
        $em->flush();

        return new Response('', 204);
    }
}
