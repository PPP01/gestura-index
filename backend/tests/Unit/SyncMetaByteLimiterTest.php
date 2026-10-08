<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Api\SyncContract;
use App\Exception\SyncProblem;
use App\Service\RateLimitGuard;
use PHPUnit\Framework\TestCase;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

/**
 * Byte-Budget-Abrechnung des sync/meta-Endpunkts im Isolationstest
 * (InMemoryStorage, kein Kernel – Muster aus SyncWriteLimiterTest).
 *
 * Geprüft werden:
 * - tokensFor() gibt die aufgerundete Anzahl korrekt zurück
 * - das Budget wird beim ersten Aufruf verbraucht
 * - ein zweiter Aufruf, der das Budget überschreitet, wirft SyncProblem 429
 */
final class SyncMetaByteLimiterTest extends TestCase
{
    private function makeBytesFactory(int $limit): RateLimiterFactory
    {
        return new RateLimiterFactory(
            ['id' => 'sync_v1_bytes_test', 'policy' => 'sliding_window', 'limit' => $limit, 'interval' => '1 hour'],
            new InMemoryStorage(),
        );
    }

    /** meta mit genau 1 KiB kostet exakt 1 Token. */
    public function testTokensForOneKiB(): void
    {
        self::assertSame(1, SyncContract::tokensFor(SyncContract::BYTES_PER_TOKEN));
    }

    /** meta mit 1 Byte über 1 KiB kostet 2 Tokens (aufgerundet). */
    public function testTokensForOneKiBPlusOneByte(): void
    {
        self::assertSame(2, SyncContract::tokensFor(SyncContract::BYTES_PER_TOKEN + 1));
    }

    /**
     * Ein meta-Request, der 1 Token verbraucht, wird bei einem Limit von 1
     * akzeptiert; der zweite würde dasselbe kosten und löst 429 aus.
     */
    public function testSecondCallExceedingBudgetCauses429(): void
    {
        $factory = $this->makeBytesFactory(1);
        $guard = new RateLimitGuard();
        $meta = str_repeat('A', SyncContract::BYTES_PER_TOKEN); // 1 Token

        // Erster Aufruf – innerhalb des Budgets
        $guard->consume($factory, '203.0.113.1', SyncContract::tokensFor(\strlen($meta)), SyncProblem::rateLimited(...));

        // Zweiter Aufruf – Budget erschöpft, muss SyncProblem::rateLimited werfen
        $this->expectException(SyncProblem::class);
        $guard->consume($factory, '203.0.113.1', SyncContract::tokensFor(\strlen($meta)), SyncProblem::rateLimited(...));
    }
}
