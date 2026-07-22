<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Exception\ApiProblem;
use App\Service\RateLimitGuard;
use PHPUnit\Framework\TestCase;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

/**
 * Isolierter Nachweis, dass der admin_login-Limiter (konfiguriert in
 * rate_limiter.yaml) über RateLimitGuard::consume() greift: nach
 * Erschöpfung des Limits wirft der Guard ApiProblem 429 statt den
 * Admin-Login unbegrenzt zuzulassen (Brute-Force-Schutz).
 */
final class AdminLoginThrottleTest extends TestCase
{
    public function testFourthLoginAttemptTripsTheLimiter(): void
    {
        $factory = new RateLimiterFactory(
            ['id' => 'admin_login', 'policy' => 'sliding_window', 'limit' => 3, 'interval' => '15 minutes'],
            new InMemoryStorage(),
        );
        $guard = new RateLimitGuard();

        $guard->consume($factory, 'ip');
        $guard->consume($factory, 'ip');
        $guard->consume($factory, 'ip');

        try {
            $guard->consume($factory, 'ip');
            self::fail('Erwartete ApiProblem-Exception blieb aus');
        } catch (ApiProblem $e) {
            self::assertSame(429, $e->getStatusCode());
            self::assertArrayHasKey('Retry-After', $e->getHeaders());
        }
    }
}
