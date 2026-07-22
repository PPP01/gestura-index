<?php
declare(strict_types=1);
namespace App\Tests\Functional\Admin;

use App\Service\WebAuthn\FakeWebAuthnCeremony;
use App\Service\WebAuthn\WebAuthnCeremony;

final class CeremonyWiringTest extends AdminTestCase
{
    public function testTestEnvUsesFake(): void
    {
        $svc = static::getContainer()->get(WebAuthnCeremony::class);
        self::assertInstanceOf(FakeWebAuthnCeremony::class, $svc);
    }
}
