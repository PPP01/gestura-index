<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Exception\SyncProblem;
use PHPUnit\Framework\TestCase;

/**
 * Vertragsform der SyncProblem-Antworten: { "error": "<code>" } als
 * application/json, nicht RFC 7807 problem+json.
 */
final class SyncProblemTest extends TestCase
{
    public function testUnavailableReturns503WithContractShape(): void
    {
        $response = SyncProblem::unavailable()->toApiResponse();

        self::assertSame(503, $response->getStatusCode());
        self::assertStringStartsWith(
            'application/json',
            (string) $response->headers->get('Content-Type'),
        );
        self::assertSame(
            ['error' => 'unavailable'],
            json_decode((string) $response->getContent(), true),
        );
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function testMaintenanceWithoutUntilReturns503(): void
    {
        $response = SyncProblem::maintenance()->toApiResponse();

        self::assertSame(503, $response->getStatusCode());
        self::assertSame(
            ['error' => 'maintenance'],
            json_decode((string) $response->getContent(), true),
        );
        self::assertNull($response->headers->get('Retry-After'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function testMaintenanceWithUntilIncludesFieldAndRetryAfter(): void
    {
        $until = new \DateTimeImmutable('+2 hours');
        $response = SyncProblem::maintenance($until)->toApiResponse();

        self::assertSame(503, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('maintenance', $body['error']);
        self::assertArrayHasKey('until', $body);
        self::assertStringEndsWith('+00:00', $body['until']);
        self::assertGreaterThan(
            0,
            (int) $response->headers->get('Retry-After'),
        );
    }
}
