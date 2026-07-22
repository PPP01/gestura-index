<?php
declare(strict_types=1);
namespace App\Security;

use App\Service\AdminSession;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

final class AdminSessionAuthenticator extends AbstractAuthenticator implements AuthenticationEntryPointInterface
{
    public function __construct(private readonly AdminSession $session) {}

    public function supports(Request $request): ?bool
    {
        return $this->session->currentUserId() !== null;
    }

    public function authenticate(Request $request): Passport
    {
        $email = $this->session->currentUserEmail();
        if ($email === null) {
            throw new AuthenticationException('No admin session');
        }

        // Der Standard-Entity-Provider (property: email) lädt den AdminUser
        // ohne Custom-Loader; login() legt die E-Mail zusätzlich in der Session ab.
        return new SelfValidatingPassport(new UserBadge($email));
    }

    public function onAuthenticationSuccess(Request $request, $token, string $firewallName): ?Response
    {
        return null; // Request normal weiterlaufen lassen
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return $this->unauthorized();
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return $this->unauthorized();
    }

    private function unauthorized(): JsonResponse
    {
        $r = new JsonResponse(['type' => 'about:blank', 'title' => 'Authentication required', 'status' => 401], 401);
        $r->headers->set('Content-Type', 'application/problem+json');
        return $r;
    }
}
