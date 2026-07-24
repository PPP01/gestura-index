<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Service\AccountDataAssembler;
use App\Service\AccountResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Vollständige Selbstauskunft (»Meine Daten«, Phase 3 E): liefert alle Daten,
 * die der Server über das Konto hält — Metadaten, Sync-Blobs inkl. Chiffrat und
 * die verknüpften Submitter mit ihren Einträgen. Reiner Lesezugriff.
 *
 * Bewusste Reihenfolge: Das Konto wird OHNE lastSeenAt-Berührung aufgelöst,
 * damit der Assembler den echten vorherigen Wert meldet (resolve() flusht die
 * Berührung sonst VOR dem Return und lastSeenAt wäre immer »jetzt«). Erst nach
 * dem Zusammenbauen berührt der Controller explizit, damit der Abruf — wie
 * jeder Konto-Request — als Aktivität fürs Prune-Fenster zählt.
 */
final class AccountDataController
{
    #[Route('/api/account/data', methods: ['GET'])]
    public function __invoke(
        Request $request,
        AccountResolver $resolver,
        AccountDataAssembler $assembler,
        EntityManagerInterface $em,
    ): JsonResponse {
        $account = $resolver->requireAccount($request, touchLastSeen: false);

        $data = $assembler->assemble($account);

        $account->lastSeenAt = new \DateTimeImmutable();
        $em->flush();

        return new JsonResponse($data);
    }
}
