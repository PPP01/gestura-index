<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Entry;
use App\Entity\Submitter;
use App\Exception\ApiProblem;
use App\Repository\SubmitterRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

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
    ) {
    }

    /** Liefert null, wenn gar kein Authorization-Header gesendet wurde. */
    public function resolve(Request $request): ?Submitter
    {
        $header = $request->headers->get('Authorization');
        if ($header === null) {
            return null;
        }

        $parsed = $this->tokens->parseAuthorizationHeader($header)
            ?? throw new ApiProblem(401, 'Invalid token');

        // Fehlversuche pro IP+Selector drosseln (Brute-Force-Schutz)
        $this->guard->consume($this->tokenAuthLimiter, ($request->getClientIp() ?? 'unknown') . '|' . $parsed['selector']);

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

    public function requireOwner(Request $request, Entry $entry): Submitter
    {
        $submitter = $this->resolve($request) ?? throw new ApiProblem(401, 'Token required');
        if ($submitter->banned) {
            throw new ApiProblem(403, 'Submitter is banned');
        }
        if ($entry->submitter->id !== $submitter->id) {
            throw new ApiProblem(403, 'Not the owner of this entry');
        }

        return $submitter;
    }
}
