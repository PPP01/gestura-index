<?php
declare(strict_types=1);
namespace App\Controller\Admin;

use App\Enum\AdminUserStatus;
use App\Exception\ApiProblem;
use App\Service\AdminSession;
use App\Service\AuditLogger;
use App\Service\RateLimitGuard;
use App\Service\WebAuthn\WebAuthnCeremony;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

final class AuthLoginController
{
    #[Route('/api/admin/auth/login', methods: ['POST'])]
    public function __invoke(
        Request $request,
        WebAuthnCeremony $ceremony,
        AdminSession $session,
        AuditLogger $audit,
        RateLimitGuard $guard,
        RateLimiterFactoryInterface $adminLoginLimiter,
        EntityManagerInterface $em,
    ): Response {
        $guard->consume($adminLoginLimiter, $request->getClientIp() ?? 'unknown');
        $user = $ceremony->verifyAssertion($request->getContent());
        if ($user->status !== AdminUserStatus::Active) {
            throw new ApiProblem(403, 'Account is not active');
        }
        $session->login($user);
        $user->lastLoginAt = new \DateTimeImmutable();
        $em->flush();
        $audit->log($user, 'auth.login');
        return new Response('', 204);
    }
}
