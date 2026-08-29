<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\AuditLogEntry;
use App\Entity\PageSetting;
use App\Enum\AdminRole;

final class AdminPageControllerTest extends AdminTestCase
{
    public function testListReturnsAllFourPages(): void
    {
        $admin = $this->createAdmin('pages-list@example.com', AdminRole::Admin);
        $this->loginWithCredentials($admin);

        $this->client->request('GET', '/api/admin/pages', server: $this->hdr());

        self::assertResponseStatusCodeSame(200);
        $data = $this->json();
        self::assertCount(4, $data);
        self::assertSame(
            ['was-ist-gestura', 'maus-gesten', 'vergleich', 'beispiele'],
            array_column($data, 'pageKey'),
        );
        foreach ($data as $row) {
            self::assertTrue($row['enabled']);
        }
    }

    public function testPatchDisablesPageAndWritesAudit(): void
    {
        $admin = $this->createAdmin('pages-patch@example.com', AdminRole::Admin);
        $this->loginWithCredentials($admin);

        $this->client->request('PATCH', '/api/admin/pages/vergleich', server: $this->hdr(),
            content: json_encode(['enabled' => false], JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(204);

        $vergleich = $this->em->getRepository(PageSetting::class)->findOneBy(['pageKey' => 'vergleich']);
        self::assertNotNull($vergleich);
        $this->em->refresh($vergleich);
        self::assertFalse($vergleich->enabled);
        self::assertSame('pages-patch@example.com', $vergleich->updatedBy);

        $entry = $this->em->getRepository(AuditLogEntry::class)->findOneBy(['action' => 'page.disable', 'targetId' => 'vergleich']);
        self::assertNotNull($entry);
        self::assertSame('page_setting', $entry->targetType);
    }

    public function testPatchUnknownKeyIs404(): void
    {
        $admin = $this->createAdmin('pages-404@example.com', AdminRole::Admin);
        $this->loginWithCredentials($admin);

        $this->client->request('PATCH', '/api/admin/pages/nope', server: $this->hdr(),
            content: json_encode(['enabled' => false], JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(404);
    }

    public function testPatchMissingEnabledFieldIs400(): void
    {
        $admin = $this->createAdmin('pages-400@example.com', AdminRole::Admin);
        $this->loginWithCredentials($admin);

        $this->client->request('PATCH', '/api/admin/pages/vergleich', server: $this->hdr(),
            content: json_encode([], JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(400);
    }

    public function testPatchWithoutCsrfHeaderIs403(): void
    {
        $admin = $this->createAdmin('pages-csrf@example.com', AdminRole::Admin);
        $this->loginWithCredentials($admin);

        $this->client->request('PATCH', '/api/admin/pages/vergleich', server: [
            'CONTENT_TYPE' => 'application/json',
        ], content: json_encode(['enabled' => false], JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(403);
    }

    public function testModeratorForbiddenFromPages(): void
    {
        $mod = $this->createAdmin('pages-mod@example.com', AdminRole::Moderator);
        $this->loginWithCredentials($mod);

        $this->client->request('GET', '/api/admin/pages', server: $this->hdr());

        self::assertResponseStatusCodeSame(403);
    }
}
