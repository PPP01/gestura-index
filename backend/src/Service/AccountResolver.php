<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Account;
use App\Exception\ApiProblem;
use App\Repository\AccountRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Löst die cookielose Bearer-Authentifizierung von End-Nutzer-Konten auf:
 * liest den Authorization-Header, prüft Selector und Verifier gegen den
 * gespeicherten Argon2id-Hash und gibt das zugehörige Account zurück.
 * Timing-Oracle-Angriffe werden durch konstante-Zeit-Verifikation gegen einen
 * Dummy-Hash bei unbekanntem Selector verhindert; ein Per-IP-Limit VOR der
 * teuren Argon2id-Prüfung deckelt CPU-/Memory-DoS.
 */
final class AccountResolver
{
    private const DUMMY_HASH = '$argon2id$v=19$m=65536,t=4,p=1$QVEua0R0WlVBVUwzbG9UNg$3HdEGtQyGMrgXeKEroenDHXyp6drNFUfnpnvSMZs0YA';

    public function __construct(
        private readonly AccountTokenService $tokens,
        private readonly AccountRepository $accounts,
        private readonly RateLimitGuard $guard,
        private readonly RateLimiterFactoryInterface $accountAuthIpLimiter,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function resolve(Request $request): ?Account
    {
        $header = $request->headers->get('Authorization');
        if ($header === null) {
            return null;
        }

        $parsed = $this->tokens->parseAuthorizationHeader($header)
            ?? throw new ApiProblem(401, 'Invalid token');

        $this->guard->consume($this->accountAuthIpLimiter, $request->getClientIp() ?? 'unknown');

        // verify() wird IMMER aufgerufen (bei unbekanntem Selector gegen den
        // Dummy-Hash), damit die Antwortzeit nicht verrät, ob ein Selector
        // existiert (Timing-Oracle).
        $account = $this->accounts->findOneBy(['tokenSelector' => $parsed['selector']]);
        $hash = $account?->tokenHash ?? self::DUMMY_HASH;
        if (!$this->tokens->verify($parsed['verifier'], $hash) || $account === null) {
            throw new ApiProblem(401, 'Invalid token');
        }

        $account->lastSeenAt = new \DateTimeImmutable();
        $this->em->flush();

        return $account;
    }

    public function requireAccount(Request $request): Account
    {
        return $this->resolve($request) ?? throw new ApiProblem(401, 'Token required');
    }
}
