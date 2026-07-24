<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Service\AccountResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Löscht das eigene Konto sofort (festgezurrtes Löschrecht). Entfernt die
 * Konto-Zeile; das Token ist danach ungültig. Liefert 204.
 */
final class AccountDeleteController
{
    #[Route('/api/account', methods: ['DELETE'])]
    public function __invoke(Request $request, AccountResolver $resolver, EntityManagerInterface $em): Response
    {
        // touchLastSeen: false — das Konto wird sofort gelöscht, ein
        // vorheriger lastSeenAt-UPDATE-Flush wäre verschwendet.
        $account = $resolver->requireAccount($request, touchLastSeen: false);
        $em->remove($account);
        $em->flush();

        return new Response('', 204);
    }
}
