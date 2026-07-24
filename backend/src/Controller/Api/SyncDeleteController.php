<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\SyncBlob;
use App\Exception\ApiProblem;
use App\Repository\SyncBlobRepository;
use App\Service\AccountResolver;
use App\Service\RateLimitGuard;
use Doctrine\DBAL\LockMode;
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

        // Löschen unter derselben Aggregat-Sperre wie der PUT (Konto-Zeile):
        // sonst könnte ein parallel laufender PUT zwischen seinem Lesen und
        // Schreiben den hier gelöschten Blob »wiederbeleben« bzw. ein 200 für
        // eine verlorene Schreiboperation melden. Das 404 wird als Sentinel
        // zurückgegeben und erst NACH dem Commit geworfen (EM-Close, lessons.md).
        $found = $em->wrapInTransaction(function () use ($em, $blobs, $account, $collection): bool {
            $em->lock($account, LockMode::PESSIMISTIC_WRITE);
            $blob = $blobs->findOneBy(['account' => $account, 'collection' => $collection]);
            if ($blob === null) {
                return false;
            }
            $em->remove($blob);

            return true;
        });

        if (!$found) {
            throw new ApiProblem(404, 'No sync data for this collection');
        }

        return new Response('', 204);
    }
}
