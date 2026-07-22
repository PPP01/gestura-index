<?php
declare(strict_types=1);
namespace App\Tests\Unit;

use App\Entity\AdminUser;
use App\Enum\AdminRole;
use PHPUnit\Framework\TestCase;

final class AdminUserRolesTest extends TestCase
{
    public function testAdminHasAdminRole(): void
    {
        $u = new AdminUser('Chef', 'chef@example.com', AdminRole::Admin);
        self::assertSame(['ROLE_ADMIN'], $u->getRoles());
        self::assertSame('chef@example.com', $u->getUserIdentifier());
        self::assertSame(0, $u->credentialCount());
    }

    public function testModeratorHasModeratorRole(): void
    {
        $u = new AdminUser('Mod', 'mod@example.com', AdminRole::Moderator);
        self::assertSame(['ROLE_MODERATOR'], $u->getRoles());
    }
}
