<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Exception\ApiProblem;
use App\Service\RateLimitGuard;
use PHPUnit\Framework\TestCase;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

final class SyncWriteLimiterTest extends TestCase
{
    public function testBlocksAfterLimitReached(): void
    {
        $factory = new RateLimiterFactory(
            ['id' => 'sync_write', 'policy' => 'sliding_window', 'limit' => 3, 'interval' => '1 hour'],
            new InMemoryStorage(),
        );
        $guard = new RateLimitGuard();

        for ($i = 0; $i < 3; ++$i) {
            $guard->consume($factory, '203.0.113.9');
        }

        try {
            $guard->consume($factory, '203.0.113.9');
            self::fail('Erwartetes ApiProblem 429 blieb aus');
        } catch (ApiProblem $e) {
            self::assertSame(429, $e->getStatusCode());
        }
    }
}
