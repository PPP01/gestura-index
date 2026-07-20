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

        $submitter = $this->submitters->findOneBy(['tokenSelector' => $parsed['selector']]);
        if ($submitter === null || !$this->tokens->verify($parsed['verifier'], $submitter->tokenHash)) {
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
