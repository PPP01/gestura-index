<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\AdminUser;
use App\Enum\AdminUserStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class AdminCreateCommandTest extends KernelTestCase
{
    public function testCreatesInvitedAdmin(): void
    {
        self::bootKernel();
        static::getContainer()->get('cache.rate_limiter')->clear();
        $app = new Application(static::$kernel);
        $tester = new CommandTester($app->find('index:admin:create'));

        $tester->execute(['displayName' => 'Chef', 'email' => 'boot@example.com', '--role' => 'admin']);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('gsta_', $tester->getDisplay());

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $em->getRepository(AdminUser::class)->findOneBy(['email' => 'boot@example.com']);
        self::assertSame(AdminUserStatus::Invited, $user->status);
    }

    public function testFailsOnDuplicateEmail(): void
    {
        self::bootKernel();
        static::getContainer()->get('cache.rate_limiter')->clear();
        $app = new Application(static::$kernel);

        $first = new CommandTester($app->find('index:admin:create'));
        $first->execute(['displayName' => 'Chef', 'email' => 'dupe@example.com', '--role' => 'admin']);
        $first->assertCommandIsSuccessful();

        $second = new CommandTester($app->find('index:admin:create'));
        $second->execute(['displayName' => 'Chef Zwei', 'email' => 'dupe@example.com', '--role' => 'admin']);

        self::assertNotSame(0, $second->getStatusCode());
        self::assertStringContainsString('existiert bereits', $second->getDisplay());
    }

    public function testFailsOnInvalidRole(): void
    {
        self::bootKernel();
        static::getContainer()->get('cache.rate_limiter')->clear();
        $app = new Application(static::$kernel);
        $tester = new CommandTester($app->find('index:admin:create'));

        $tester->execute(['displayName' => 'Chef', 'email' => 'invalid-role@example.com', '--role' => 'superadmin']);

        self::assertNotSame(0, $tester->getStatusCode());
        self::assertStringContainsString('Ungültige Rolle', $tester->getDisplay());
    }
}
