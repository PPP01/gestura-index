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
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Lädt einen clientseitig verschlüsselten Sync-Blob hoch (Zero-Knowledge:
 * der Server prüft nur Größe und Versionszähler, nie den Inhalt).
 * Optimistisches Locking: baseVersion muss dem Server-Stand entsprechen
 * (leerer Slot = 0), sonst 409 mit der aktuellen Version – der Client lädt
 * dann neu, merged lokal und versucht es erneut.
 */
final class SyncPutController
{
    #[Route('/api/account/sync/{collection}', methods: ['PUT'])]
    public function __invoke(
        string $collection,
        Request $request,
        AccountResolver $resolver,
        SyncBlobRepository $blobs,
        EntityManagerInterface $em,
        RateLimitGuard $guard,
        RateLimiterFactoryInterface $syncWriteLimiter,
    ): JsonResponse {
        $account = $resolver->requireAccount($request);
        if (!\in_array($collection, SyncBlob::COLLECTIONS, true)) {
            throw new ApiProblem(400, 'Unknown collection');
        }
        $guard->consume($syncWriteLimiter, $request->getClientIp() ?? 'unknown');

        try {
            $body = json_decode($request->getContent(), true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ApiProblem(400, 'Invalid JSON body');
        }
        $baseVersion = $body['baseVersion'] ?? null;
        $ciphertext = $body['ciphertext'] ?? null;
        if (!\is_int($baseVersion) || $baseVersion < 0) {
            throw new ApiProblem(400, 'baseVersion must be a non-negative integer');
        }
        if (!\is_string($ciphertext) || $ciphertext === '') {
            throw new ApiProblem(400, 'ciphertext must be a non-empty string');
        }
        if (\strlen($ciphertext) > SyncBlob::MAX_CIPHERTEXT_BYTES) {
            throw new ApiProblem(413, 'Ciphertext too large');
        }

        // Konfliktprüfung + Schreiben atomar. Die Konto-Zeile dient als
        // Aggregat-Sperre: sie existiert immer (anders als der Blob beim
        // Erst-Upload) und serialisiert damit auch zwei parallele erste
        // Uploads, die sonst beide »Slot leer« sähen und in die Unique-
        // Constraint-Verletzung liefen. Der 409 wird als Sentinel
        // ZURÜCKGEGEBEN, nie aus der Closure geworfen – ein Throw würde den
        // EntityManager schließen (lessons.md).
        /** @var array{version: int}|array{conflict: int} $result */
        $result = $em->wrapInTransaction(function () use ($em, $blobs, $account, $collection, $baseVersion, $ciphertext): array {
            $em->lock($account, LockMode::PESSIMISTIC_WRITE);
            $blob = $blobs->findOneBy(['account' => $account, 'collection' => $collection]);
            if ($blob !== null) {
                // Nach dem Warten auf die Sperre den frischen Stand lesen:
                $em->refresh($blob);
            }
            $current = $blob?->version ?? 0;
            if ($baseVersion !== $current) {
                return ['conflict' => $current];
            }
            if ($blob === null) {
                $blob = new SyncBlob($account, $collection, $ciphertext);
                $em->persist($blob);
            } else {
                $blob->ciphertext = $ciphertext;
                $blob->version = $current + 1;
                $blob->updatedAt = new \DateTimeImmutable();
            }

            return ['version' => $blob->version];
        });

        if (isset($result['conflict'])) {
            throw new ApiProblem(409, 'Sync version conflict', ['version' => $result['conflict']]);
        }

        return new JsonResponse(['version' => $result['version']]);
    }
}
