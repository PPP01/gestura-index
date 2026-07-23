<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Service\AccountResolver;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Prüft die Gültigkeit eines Konto-Tokens. Liefert minimal den Erstellzeitpunkt
 * (kein Nutzer-Datum vorhanden) bzw. 401 bei ungültigem/gelöschtem Token –
 * so erkennt die Extension ein nicht mehr gültiges Token.
 */
final class AccountMeController
{
    #[Route('/api/account/me', methods: ['GET'])]
    public function __invoke(Request $request, AccountResolver $resolver): JsonResponse
    {
        $account = $resolver->requireAccount($request);

        return new JsonResponse(['createdAt' => $account->createdAt->format(\DateTimeInterface::ATOM)]);
    }
}
