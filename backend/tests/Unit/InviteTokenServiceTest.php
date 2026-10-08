<?php
declare(strict_types=1);
namespace App\Tests\Unit;

use App\Service\InviteTokenService;
use PHPUnit\Framework\TestCase;

final class InviteTokenServiceTest extends TestCase
{
    public function testRoundTrip(): void
    {
        $svc = new InviteTokenService();
        $gen = $svc->generate();
        self::assertMatchesRegularExpression('/^gsta_[0-9a-f]{16}_[A-Za-z0-9_-]{43}$/', $gen->token);

        $parsed = $svc->parse($gen->token);
        self::assertNotNull($parsed);
        self::assertSame($gen->selector, $parsed['selector']);
        self::assertTrue($svc->verify($parsed['verifier'], $gen->hash));
        self::assertFalse($svc->verify('wrong', $gen->hash));
    }

    public function testParseRejectsGarbage(): void
    {
        self::assertNull((new InviteTokenService())->parse('nope'));
    }

    /**
     * Ein Token mit abschließendem \n muss null liefern. Ohne /D-Modifier in
     * PATTERN würde $ auch vor einem abschließenden \n matchen und das Token
     * fälschlicherweise als gültig akzeptieren (kein trim() vor dem Match).
     */
    public function testParseRejectsTokenWithTrailingNewline(): void
    {
        $svc = new InviteTokenService();
        $gen = $svc->generate();

        self::assertNull($svc->parse($gen->token . "\n"));
    }
}
