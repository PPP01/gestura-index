<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\ApiProblem;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

final class RateLimitGuard
{
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
