<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Controller\Api\LocatorSyncPingController;
use App\Exception\SyncProblem;
use App\Service\RateLimitGuard;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\RateLimiter\LimiterInterface;

/**
 * Prüft den try/catch um SELECT 1: ein DB-Fehler muss als
 * SyncProblem::unavailable (503, "unavailable") geworfen werden,
 * nicht als unkontrollierte Exception, die ProblemJsonSubscriber zu
 * einem 500 problem+json machen würde (Terra M2, Gemini H-2).
 */
final class LocatorSyncPingControllerTest extends TestCase
{
    public function testDbFailureThrowsSyncProblemUnavailable(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('executeQuery')
            ->willThrowException(new \RuntimeException('DB down'));

        $limiter = $this->createStub(LimiterInterface::class);
        $rateLimit = $this->createStub(RateLimit::class);
        $rateLimit->method('isAccepted')->willReturn(true);
        $limiter->method('consume')->willReturn($rateLimit);

        $factory = $this->createStub(RateLimiterFactoryInterface::class);
        $factory->method('create')->willReturn($limiter);

        $guard = new RateLimitGuard();

        $controller = new LocatorSyncPingController();

        $this->expectException(SyncProblem::class);

        try {
            $controller(
                new Request(),
                $connection,
                $guard,
                $factory,
            );
        } catch (SyncProblem $e) {
            self::assertSame(503, $e->getStatusCode());
            self::assertSame('unavailable', $e->errorCode);
            throw $e;
        }
    }
}
