<?php
declare(strict_types=1);
namespace App\Tests\Functional\Admin;

use App\Enum\AdminRole;

final class AuditTest extends AdminTestCase
{
    public function testAdminSeesAudit(): void
    {
        $admin = $this->createAdmin('chef@example.com', AdminRole::Admin);
        $this->loginWithCredentials($admin); // erzeugt auth.login-Audit
        $this->client->request('GET', '/api/admin/audit', server: $this->hdr());
        self::assertResponseStatusCodeSame(200);
        self::assertArrayHasKey('items', $this->json());
    }

    public function testModeratorForbidden(): void
    {
        $mod = $this->createAdmin('mod@example.com', AdminRole::Moderator);
        $this->loginWithCredentials($mod);
        $this->client->request('GET', '/api/admin/audit', server: $this->hdr());
        self::assertResponseStatusCodeSame(403);
    }
}
