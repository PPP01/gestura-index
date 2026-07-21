<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\ApiProblem;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Zentrale Hilfsmethode zur Rate-Limit-Durchsetzung: kapselt den
 * Symfony-RateLimiter und wirft bei Erschöpfung ein einheitliches
 * ApiProblem 429 mit Retry-After-Angabe.
 */
final class RateLimitGuard
{
    /**
     * Verbraucht ein Token des Rate-Limiters für den angegebenen Schlüssel.
     * Wirft ApiProblem 429 mit dem Header »Retry-After« in Sekunden,
     * sobald das Limit erschöpft ist.
     */
    public function consume(RateLimiterFactoryInterface $factory, string $key): void
    {
        $limit = $factory->create($key)->consume();
        if ($limit->isAccepted()) {
            return;
        }

        $retryAfter = max(1, $limit->getRetryAfter()->getTimestamp() - time());

        throw new ApiProblem(429, 'Rate limit exceeded', ['retryAfter' => $retryAfter], ['Retry-After' => (string) $retryAfter]);
    }
}
