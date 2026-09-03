<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Exception\ApiProblem;
use App\Service\RateLimitGuard;
use PHPUnit\Framework\TestCase;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Yaml\Yaml;

final class UpdateCheckLimiterTest extends TestCase
{
    /**
     * Der Limiter muss in allen drei Konfigurationsblöcken stehen – sonst
     * fehlt er in genau der Umgebung, in der niemand hinschaut.
     */
    public function testLimiterIsConfiguredInEveryEnvironmentBlock(): void
    {
        $config = Yaml::parseFile(\dirname(__DIR__, 2) . '/config/packages/rate_limiter.yaml');

        self::assertSame(
            ['policy' => 'sliding_window', 'limit' => 60, 'interval' => '1 hour'],
            $config['framework']['rate_limiter']['update_check'],
        );
        self::assertArrayHasKey('update_check', $config['when@test']['framework']['rate_limiter']);
        self::assertArrayHasKey('update_check', $config['when@dev']['framework']['rate_limiter']);
    }

    public function testBlocksAfterLimitReached(): void
    {
        $factory = new RateLimiterFactory(
            ['id' => 'update_check', 'policy' => 'sliding_window', 'limit' => 3, 'interval' => '1 hour'],
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
