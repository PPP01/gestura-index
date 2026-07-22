<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\AdminUser;
use App\Enum\AdminRole;
use App\Enum\AdminUserStatus;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;

final class UserManagementTest extends AdminTestCase
{
    use MailerAssertionsTrait;

    public function testInviteSendsEmailAndCreatesInvitedUser(): void
    {
        $admin = $this->createAdmin('chef@example.com', AdminRole::Admin);
        $this->loginWithCredentials($admin, 2);

        $this->client->request('POST', '/api/admin/users', server: $this->hdr(),
            content: json_encode(['displayName' => 'Neu', 'email' => 'neu@example.com', 'role' => 'moderator'], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);
        self::assertEmailCount(1);

        $invited = $this->em->getRepository(AdminUser::class)->findOneBy(['email' => 'neu@example.com']);
        self::assertSame(AdminUserStatus::Invited, $invited->status);
    }

    public function testInviteRejectsInvalidRole(): void
    {
        $admin = $this->createAdmin('chef2@example.com', AdminRole::Admin);
        $this->loginWithCredentials($admin, 2);

        $this->client->request('POST', '/api/admin/users', server: $this->hdr(),
            content: json_encode(['displayName' => 'Neu', 'email' => 'neu2@example.com', 'role' => 'superadmin'], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(400);
    }

    public function testInviteRejectsDuplicateEmail(): void
    {
        $admin = $this->createAdmin('chef3@example.com', AdminRole::Admin);
        $this->loginWithCredentials($admin, 2);

        $this->client->request('POST', '/api/admin/users', server: $this->hdr(),
            content: json_encode(['displayName' => 'Dup', 'email' => 'chef3@example.com', 'role' => 'moderator'], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(409);
    }

    public function testModeratorCannotInvite(): void
    {
        $mod = $this->createAdmin('mod@example.com', AdminRole::Moderator);
        $this->loginWithCredentials($mod);
        $this->client->request('POST', '/api/admin/users', server: $this->hdr(),
            content: json_encode(['displayName' => 'X', 'email' => 'x@example.com', 'role' => 'moderator'], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(403);
    }

    public function testListReturnsUsers(): void
    {
        $admin = $this->createAdmin('chef4@example.com', AdminRole::Admin);
        $this->loginWithCredentials($admin, 2);

        $this->client->request('GET', '/api/admin/users', server: $this->hdr());
        self::assertResponseStatusCodeSame(200);
        $data = $this->json();
        self::assertNotEmpty($data['users']);
    }

    public function testDisableSetsStatus(): void
    {
        $admin = $this->createAdmin('chef5@example.com', AdminRole::Admin);
        $this->loginWithCredentials($admin, 2);
        $target = $this->createAdmin('target@example.com', AdminRole::Moderator);

        $this->client->request('POST', "/api/admin/users/{$target->id}/disable", server: $this->hdr());
        self::assertResponseStatusCodeSame(204);

        $this->em->refresh($target);
        self::assertSame(AdminUserStatus::Disabled, $target->status);
    }

    public function testReinviteSendsNewEmail(): void
    {
        $admin = $this->createAdmin('chef6@example.com', AdminRole::Admin);
        $this->loginWithCredentials($admin, 2);
        $target = $this->createAdmin('reinvite@example.com', AdminRole::Moderator, AdminUserStatus::Invited);

        $this->client->request('POST', "/api/admin/users/{$target->id}/reinvite", server: $this->hdr());
        self::assertResponseStatusCodeSame(201);
        self::assertEmailCount(1);
    }

    public function testReinviteRequiresStepUpAndBackup(): void
    {
        $admin = $this->createAdmin('chef7@example.com', AdminRole::Admin);
        $this->loginWithCredentials($admin, 1);
        $target = $this->createAdmin('reinvite2@example.com', AdminRole::Moderator, AdminUserStatus::Invited);

        $this->client->request('POST', "/api/admin/users/{$target->id}/reinvite", server: $this->hdr());
        self::assertResponseStatusCodeSame(409);
    }

    public function testReinviteRejectedForDisabledUser(): void
    {
        $admin = $this->createAdmin('chef8@example.com', AdminRole::Admin);
        $this->loginWithCredentials($admin, 2);
        $target = $this->createAdmin('disabled2@example.com', AdminRole::Moderator, AdminUserStatus::Disabled);

        $this->client->request('POST', "/api/admin/users/{$target->id}/reinvite", server: $this->hdr());
        self::assertResponseStatusCodeSame(409);
    }

    public function testReinviteRecoveryForActiveUserSucceeds(): void
    {
        $admin = $this->createAdmin('chef9@example.com', AdminRole::Admin);
        $this->loginWithCredentials($admin, 2);
        $target = $this->createAdmin('active-recovery@example.com', AdminRole::Moderator, AdminUserStatus::Active);

        $this->client->request('POST', "/api/admin/users/{$target->id}/reinvite", server: $this->hdr());
        self::assertResponseStatusCodeSame(201);
        self::assertEmailCount(1);
    }
}
