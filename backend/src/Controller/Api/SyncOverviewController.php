<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Repository\SyncBlobRepository;
use App\Service\AccountResolver;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Billiger Änderungs-Poll: listet je belegter Collection Version, Zeitstempel
 * und Größe – ohne Chiffrat. Der Client vergleicht die Versionen mit seinem
 * letzten Stand und lädt nur bei Abweichung das Chiffrat nach.
 */
final class SyncOverviewController
{
    #[Route('/api/account/sync', methods: ['GET'])]
    public function __invoke(Request $request, AccountResolver $resolver, SyncBlobRepository $blobs): JsonResponse
    {
        $account = $resolver->requireAccount($request);

        $collections = [];
        foreach ($blobs->findBy(['account' => $account]) as $blob) {
            $collections[$blob->collection] = [
                'version' => $blob->version,
                'updatedAt' => $blob->updatedAt->format(\DateTimeInterface::ATOM),
                'size' => \strlen($blob->ciphertext),
            ];
        }

        // (object)-Cast, damit ein leeres Ergebnis als {} statt [] serialisiert:
        return new JsonResponse(['collections' => (object) $collections]);
    }
}
