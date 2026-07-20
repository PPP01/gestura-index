<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Exception\ApiProblem;
use App\Service\RateLimitGuard;
use PHPUnit\Framework\TestCase;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

final class RateLimitGuardTest extends TestCase
{
    public function testThrows429WithRetryAfterWhenLimitExceeded(): void
    {
        $factory = new RateLimiterFactory(
            ['id' => 'test', 'policy' => 'fixed_window', 'limit' => 2, 'interval' => '1 hour'],
            new InMemoryStorage(),
        );
        $guard = new RateLimitGuard();

        $guard->consume($factory, 'key');
        $guard->consume($factory, 'key');

        try {
            $guard->consume($factory, 'key');
            self::fail('Erwartete ApiProblem-Exception blieb aus');
        } catch (ApiProblem $e) {
            self::assertSame(429, $e->getStatusCode());
            self::assertArrayHasKey('Retry-After', $e->getHeaders());
        }
    }
}
