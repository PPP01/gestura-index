<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Account;
use App\Service\AccountTokenService;
use App\Service\RateLimitGuard;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Legt ein anonymes End-Nutzer-Konto an und gibt das Bearer-Token EINMALIG
 * zurück – die einzige Stelle, an der das Klartext-Token je herausgegeben wird.
 * Kein Request-Body, keine Nutzerdaten. Per-IP-Limit gegen Massen-Erstellung.
 */
final class AccountCreateController
{
    #[Route('/api/account', methods: ['POST'])]
    public function __invoke(
        Request $request,
        AccountTokenService $tokens,
        EntityManagerInterface $em,
        RateLimitGuard $guard,
        RateLimiterFactoryInterface $accountCreateLimiter,
    ): JsonResponse {
        $guard->consume($accountCreateLimiter, $request->getClientIp() ?? 'unknown');

        $generated = $tokens->generate();
        $account = new Account($generated->selector, $generated->hash);
        $em->persist($account);
        $em->flush();

        return new JsonResponse(['token' => $generated->token], 201);
    }
}
