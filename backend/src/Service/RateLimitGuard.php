<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\ApiProblem;
use App\Exception\SyncProblem;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Zentrale Hilfsmethode zur Rate-Limit-Durchsetzung: kapselt den
 * Symfony-RateLimiter und wirft bei Erschöpfung einen einheitlichen
 * 429er mit Retry-After-Angabe.
 */
final class RateLimitGuard
{
    /**
     * Verbraucht $tokens des Rate-Limiters für den angegebenen Schlüssel und
     * wirft bei Erschöpfung 429 mit dem Header »Retry-After« in Sekunden.
     *
     * $tokens > 1 dient dem mengenbasierten Limit (geschriebene KiB), nicht
     * der Anfragenzahl – der Vertrag der Extension verlangt für den
     * Locator-Sync ausdrücklich beides.
     *
     * $problem wählt die Fehlerform: die Locator-Sync-Endpunkte antworten in
     * der Vertragsform { "error": "rate-limited" }, alle übrigen in RFC 7807.
     *
     * @param class-string<ApiProblem|SyncProblem> $problem
     */
    public function consume(
        RateLimiterFactoryInterface $factory,
        string $key,
        int $tokens = 1,
        string $problem = ApiProblem::class,
    ): void {
        $limit = $factory->create($key)->consume($tokens);
        if ($limit->isAccepted()) {
            return;
        }

        $retryAfter = max(1, $limit->getRetryAfter()->getTimestamp() - time());

        throw $problem === SyncProblem::class
            ? SyncProblem::rateLimited($retryAfter)
            : new ApiProblem(429, 'Rate limit exceeded', ['retryAfter' => $retryAfter], ['Retry-After' => (string) $retryAfter]);
    }
}
