<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Account;
use App\Entity\Entry;
use App\Entity\Submitter;
use App\Exception\ApiProblem;
use App\Repository\SubmitterRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Löst anonyme Edit-Token-Authentifizierung auf: liest den Authorization-Header,
 * prüft Selector und Verifier gegen gespeicherte Argon2id-Hashes und gibt den
 * zugehörigen Submitter zurück. Timing-Oracle-Angriffe werden durch Dummy-Hash-
 * Verifikation bei unbekanntem Selector verhindert.
 */
final class SubmitterResolver
{
    /**
     * Argon2id-Hash eines Zufallswerts: erzwingt konstante Rechenzeit,
     * damit unbekannte Selectors nicht per Timing erkennbar sind.
     */
    private const DUMMY_HASH = '$argon2id$v=19$m=65536,t=4,p=1$QVEua0R0WlVBVUwzbG9UNg$3HdEGtQyGMrgXeKEroenDHXyp6drNFUfnpnvSMZs0YA';

    public function __construct(
        private readonly EditTokenService $tokens,
        private readonly SubmitterRepository $submitters,
        private readonly RateLimitGuard $guard,
        private readonly RateLimiterFactoryInterface $tokenAuthLimiter,
        private readonly RateLimiterFactoryInterface $tokenAuthIpLimiter,
        private readonly AccountResolver $accountResolver,
    ) {
    }

    /**
     * Löst den Authorization-Header auf und gibt den zugehörigen Submitter zurück.
     * Liefert null, wenn kein Header gesendet wurde. Wirft ApiProblem 401 bei
     * fehlendem Bearer-Prefix; die eigentliche Schutzkette (Rate-Limits,
     * konstante Zeit) läuft in resolveFromEditToken().
     */
    public function resolve(Request $request): ?Submitter
    {
        $header = $request->headers->get('Authorization');
        if ($header === null) {
            return null;
        }
        if (!str_starts_with($header, 'Bearer ')) {
            throw new ApiProblem(401, 'Invalid token');
        }

        return $this->resolveFromEditToken(substr($header, 7), $request);
    }

    /**
     * Verifiziert ein rohes Edit-Token (z. B. aus einem Request-Body) über
     * dieselbe Schutzkette wie resolve(): Per-IP-Limit VOR der Argon2id-
     * Verifikation, konstante Zeit via Dummy-Hash, Limit pro IP+Selector.
     * Wirft ApiProblem 401 bei ungültigem Token.
     */
    public function resolveFromEditToken(string $editToken, Request $request): Submitter
    {
        $parsed = $this->tokens->parseToken($editToken)
            ?? throw new ApiProblem(401, 'Invalid token');

        $ip = $request->getClientIp() ?? 'unknown';
        // Unabhängiges Per-IP-Limit VOR der teuren Argon2id-(Dummy-)Verifikation:
        // Der Selector-Bucket allein reicht nicht, weil ein Angreifer pro Anfrage
        // einen neuen Selector wählen und so je einen eigenen Bucket erzeugen
        // könnte — die Hash-Prüfung ließe sich damit unbegrenzt auslösen
        // (CPU-/Memory-DoS). Das IP-Limit deckelt Versuche selektorunabhängig.
        $this->guard->consume($this->tokenAuthIpLimiter, $ip);
        // Fehlversuche zusätzlich pro IP+Selector drosseln (Brute-Force-Schutz)
        $this->guard->consume($this->tokenAuthLimiter, $ip . '|' . $parsed['selector']);

        // verify() wird IMMER aufgerufen (auch bei unbekanntem Selector, dann
        // gegen DUMMY_HASH) — sonst ließe sich über die Antwortzeit erkennen,
        // ob ein Selector existiert (Timing-Oracle: Argon2id-Hashing kostet
        // spürbar Rechenzeit, ein reines "Submitter nicht gefunden" nicht).
        $submitter = $this->submitters->findOneBy(['tokenSelector' => $parsed['selector']]);
        $hash = $submitter?->tokenHash ?? self::DUMMY_HASH;
        if (!$this->tokens->verify($parsed['verifier'], $hash) || $submitter === null) {
            throw new ApiProblem(401, 'Invalid token');
        }

        return $submitter;
    }

    /**
     * Wie resolve(), verlangt aber zwingend einen gültigen Token und prüft
     * zusätzlich, ob der Submitter Eigentümer des Eintrags und nicht gesperrt ist.
     * Wirft ApiProblem 401 ohne Token, 403 bei Sperre oder fremdem Eintrag.
     *
     * gacc_-Zweig: akzeptiert statt eines Edit-Tokens ein Konto-Bearer-Token
     * (Header `Bearer gacc_...`). Eigentum besteht dann, wenn der Submitter des
     * Entrys mit genau diesem Konto verknüpft ist. Ban-Bündel ist ein davon
     * getrennter 403-Fall ('Account is banned'): Ist irgendein mit dem Konto
     * verknüpfter Submitter gesperrt, blockiert das das gesamte
     * konto-basierte Verwalten, unabhängig von der Eigentümerschaft dieses
     * konkreten Entrys.
     */
    public function requireOwner(Request $request, Entry $entry): Submitter
    {
        $header = $request->headers->get('Authorization') ?? '';
        if (str_starts_with($header, 'Bearer gacc_')) {
            $account = $this->requireUnbannedAccount($request);
            if ($entry->submitter->account?->id !== $account->id) {
                throw new ApiProblem(403, 'Not the owner of this entry');
            }

            return $entry->submitter;
        }

        $submitter = $this->resolve($request) ?? throw new ApiProblem(401, 'Token required');
        if ($submitter->banned) {
            throw new ApiProblem(403, 'Submitter is banned');
        }
        if ($entry->submitter->id !== $submitter->id) {
            throw new ApiProblem(403, 'Not the owner of this entry');
        }

        return $submitter;
    }

    /**
     * Löst das Konto aus einem gacc_-Bearer-Header auf und erzwingt die
     * Ban-Bündel-Regel: Ist irgendein mit dem Konto verknüpfter Submitter
     * gesperrt, ist das gesamte konto-basierte Einreichen/Verwalten blockiert
     * (403). Gemeinsamer Guard für requireOwner() und den Submit-Pfad, damit
     * die Sperr-Regel nicht in zwei Kopien auseinanderdriften kann.
     */
    public function requireUnbannedAccount(Request $request): Account
    {
        $account = $this->accountResolver->requireAccount($request);
        if ($this->submitters->hasBannedForAccount($account)) {
            throw new ApiProblem(403, 'Account is banned');
        }

        return $account;
    }
}
