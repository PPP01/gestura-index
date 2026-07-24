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
    /**
     * Argon2id-Hash eines Zufallswerts: erzwingt konstante Rechenzeit bei
     * unbekanntem Selector, damit die Antwortzeit nicht verrät, ob ein Konto
     * existiert (Timing-Oracle-Schutz). Bewusste, dokumentierte Kopie desselben
     * Werts in SubmitterResolver — beide Resolver folgen demselben Muster.
     */
    private const DUMMY_HASH = '$argon2id$v=19$m=65536,t=4,p=1$QVEua0R0WlVBVUwzbG9UNg$3HdEGtQyGMrgXeKEroenDHXyp6drNFUfnpnvSMZs0YA';

    public function __construct(
        private readonly AccountTokenService $tokens,
        private readonly AccountRepository $accounts,
        private readonly RateLimitGuard $guard,
        private readonly RateLimiterFactoryInterface $accountAuthIpLimiter,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Löst den Authorization-Header zu einem Konto auf. Gibt null zurück, wenn
     * kein Header gesendet wurde; wirft ApiProblem(401) bei ungültigem Token.
     * Aktualisiert bei Erfolg lastSeenAt (Grundlage fürs Aufräumen inaktiver
     * Konten) — außer $touchLastSeen ist false, etwa wenn der Aufrufer das Konto
     * unmittelbar danach löscht und der zusätzliche UPDATE-Flush unnötig wäre.
     */
    public function resolve(Request $request, bool $touchLastSeen = true): ?Account
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

        if ($touchLastSeen) {
            $account->lastSeenAt = new \DateTimeImmutable();
            $this->em->flush();
        }

        return $account;
    }

    /**
     * Wie resolve(), verlangt aber zwingend einen gültigen Token und wirft
     * ApiProblem(401), wenn keiner gesendet wurde. $touchLastSeen wird
     * durchgereicht (siehe resolve()).
     */
    public function requireAccount(Request $request, bool $touchLastSeen = true): Account
    {
        return $this->resolve($request, $touchLastSeen) ?? throw new ApiProblem(401, 'Token required');
    }
}
