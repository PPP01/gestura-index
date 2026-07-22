<?php
declare(strict_types=1);
namespace App\Tests\Functional\Admin;

use App\Entity\AdminUser;
use App\Enum\AdminUserStatus;

final class UserCheckerTest extends AdminTestCase
{
    /**
     * Der UserChecker muss auf JEDEM authentifizierten Request greifen, nicht
     * nur beim Login: ein Admin, der NACH dem Login deaktiviert wird, darf
     * seine Session nicht bis zum Idle-Timeout (~30 min) behalten dürfen.
     */
    public function testDisabledAfterLoginLosesAccess(): void
    {
        $admin = $this->createAdmin(status: AdminUserStatus::Active);
        $adminId = $admin->id;
        $this->loginWithCredentials($admin);

        $this->client->request('GET', '/api/admin/auth/me', server: $this->hdr());
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        // Der Client-Request eben hat die Kernel-Reboot-bedingte
        // EntityManager-Identity-Map bereits geleert ($admin ist damit
        // detached) — daher frisch aus der DB nachladen, statt die
        // detachte Instanz zu mutieren (sonst bliebe der flush() ein No-op).
        $admin = $this->em->getRepository(AdminUser::class)->find($adminId);
        $admin->status = AdminUserStatus::Disabled;
        $this->em->flush();
        $this->em->clear();

        $this->client->request('GET', '/api/admin/auth/me', server: $this->hdr());
        self::assertSame(401, $this->client->getResponse()->getStatusCode());
    }
}
