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
    /** Idle-Timeout in Sekunden: 30 Minuten ohne Aktivität beenden die Session. */
    private const IDLE_TTL = 1800;

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

        // Deterministischer Idle-Timeout: seit der letzten Aktivität dürfen
        // höchstens 30 Minuten vergangen sein. gc_maxlifetime räumt Sessions
        // nur probabilistisch weg (auf Shared-Hosting oft gar nicht) – dieser
        // Check erzwingt das Ablaufen pro Request und verwirft die Session hart.
        $last = $this->session->lastActivityAt();
        if ($last === null || (time() - $last) > self::IDLE_TTL) {
            $this->session->logout();
            throw new AuthenticationException('Session idle timeout');
        }
        $this->session->touchActivity();

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
