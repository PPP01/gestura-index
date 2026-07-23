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
        // Erst DB + Audit erfolgreich abschließen, DANN die Session aktivieren.
        // Andernfalls hätte der Client bei einem Fehler der Audit-Transaktion
        // (500) bereits eine authentifizierte Session ohne zugehörigen
        // Login-Audit-Eintrag.
        $em->wrapInTransaction(function () use ($user, $audit): void {
            $user->lastLoginAt = new \DateTimeImmutable();
            $audit->log($user, 'auth.login');
        });
        $session->login($user);
        return new Response('', 204);
    }
}
