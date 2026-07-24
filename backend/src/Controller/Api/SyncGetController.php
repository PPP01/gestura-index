<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\SyncBlob;
use App\Exception\ApiProblem;
use App\Repository\SyncBlobRepository;
use App\Service\AccountResolver;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Liefert das Chiffrat einer Collection samt Versionszähler – der Client
 * entschlüsselt, merged lokal und lädt per PUT wieder hoch.
 */
final class SyncGetController
{
    #[Route('/api/account/sync/{collection}', methods: ['GET'])]
    public function __invoke(string $collection, Request $request, AccountResolver $resolver, SyncBlobRepository $blobs): JsonResponse
    {
        $account = $resolver->requireAccount($request);
        if (!\in_array($collection, SyncBlob::COLLECTIONS, true)) {
            throw new ApiProblem(400, 'Unknown collection');
        }
        $blob = $blobs->findOneBy(['account' => $account, 'collection' => $collection])
            ?? throw new ApiProblem(404, 'No sync data for this collection');

        return new JsonResponse(['version' => $blob->version, 'ciphertext' => $blob->ciphertext]);
    }
}
