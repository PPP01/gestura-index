<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Service\EditTokenService;
use PHPUnit\Framework\TestCase;

final class EditTokenServiceTest extends TestCase
{
    private EditTokenService $service;

    protected function setUp(): void
    {
        $this->service = new EditTokenService();
    }

    public function testGenerateProducesParsableTokenThatVerifies(): void
    {
        $generated = $this->service->generate();

        self::assertMatchesRegularExpression('/^gsti_[0-9a-f]{16}_[A-Za-z0-9_-]{43}$/', $generated->token);
        self::assertStringStartsWith('$argon2id$', $generated->hash);

        $parsed = $this->service->parseAuthorizationHeader('Bearer ' . $generated->token);
        self::assertNotNull($parsed);
        self::assertSame($generated->selector, $parsed['selector']);
        self::assertTrue($this->service->verify($parsed['verifier'], $generated->hash));
    }

    public function testWrongVerifierFailsVerification(): void
    {
        $generated = $this->service->generate();
        $other = $this->service->generate();
        $parsed = $this->service->parseAuthorizationHeader('Bearer ' . $other->token);
        self::assertFalse($this->service->verify($parsed['verifier'], $generated->hash));
    }

    public function testMalformedHeadersReturnNull(): void
    {
        self::assertNull($this->service->parseAuthorizationHeader(null));
        self::assertNull($this->service->parseAuthorizationHeader('Bearer kaputt'));
        self::assertNull($this->service->parseAuthorizationHeader('Basic abc'));
        self::assertNull($this->service->parseAuthorizationHeader('Bearer gsti_zzzz_kurz'));
    }
}
