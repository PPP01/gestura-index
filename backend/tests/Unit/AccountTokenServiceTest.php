<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Service\AccountTokenService;
use PHPUnit\Framework\TestCase;

final class AccountTokenServiceTest extends TestCase
{
    public function testGenerateProducesWellFormedTokenAndMatchingHash(): void
    {
        $gen = (new AccountTokenService())->generate();
        self::assertMatchesRegularExpression('/^gacc_[0-9a-f]{16}_[A-Za-z0-9_-]{43}$/', $gen->token);
        self::assertSame(16, strlen($gen->selector));
        [, , $verifier] = explode('_', $gen->token, 3);
        self::assertTrue(password_verify($verifier, $gen->hash));
    }

    public function testParseValidHeaderReturnsSelectorAndVerifier(): void
    {
        $svc = new AccountTokenService();
        $gen = $svc->generate();
        $parsed = $svc->parseAuthorizationHeader('Bearer ' . $gen->token);
        self::assertNotNull($parsed);
        self::assertSame($gen->selector, $parsed['selector']);
    }

    public function testParseRejectsMissingBearerForeignPrefixAndGarbage(): void
    {
        $svc = new AccountTokenService();
        self::assertNull($svc->parseAuthorizationHeader(null));
        self::assertNull($svc->parseAuthorizationHeader('Bearer nonsense'));
        // Edit-Token-Präfix (gsti_) darf NICHT als Konto-Token durchgehen:
        self::assertNull($svc->parseAuthorizationHeader('Bearer gsti_' . str_repeat('a', 16) . '_' . str_repeat('b', 43)));
    }

    public function testVerifyRejectsWrongVerifier(): void
    {
        $svc = new AccountTokenService();
        $gen = $svc->generate();
        self::assertFalse($svc->verify('wrong-verifier', $gen->hash));
    }
}
