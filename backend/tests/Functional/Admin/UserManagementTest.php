<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Controller\Admin\UserDisableController;
use App\Entity\AdminUser;
use App\Enum\AdminRole;
use App\Enum\AdminUserStatus;
use App\Exception\ApiProblem;
use App\Repository\AdminUserRepository;
use App\Security\StepUpGuard;
use App\Service\AdminSession;
use App\Service\AuditLogger;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

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

    public function testCannotDisableOwnAccount(): void
    {
        $admin = $this->createAdmin('self-disable@example.com', AdminRole::Admin);
        // Zweiter aktiver Admin, damit ausschließlich die Selbst-Sperre
        // geprüft wird, nicht der Letzter-Admin-Schutz.
        $this->createAdmin('self-disable-2@example.com', AdminRole::Admin);
        $this->loginWithCredentials($admin, 2);

        $this->client->request('POST', "/api/admin/users/{$admin->id}/disable", server: $this->hdr());
        self::assertResponseStatusCodeSame(409);

        $reloaded = $this->em->getRepository(AdminUser::class)->find($admin->id);
        self::assertSame(AdminUserStatus::Active, $reloaded->status);
    }

    /**
     * Über HTTP nicht reproduzierbar: AdminUserChecker verlangt bei JEDEM
     * Request einen aktiven Admin-Akteur (sonst 401) – der Akteur zählt
     * also stets selbst mit zu den aktiven Admins, wodurch die Zielperson
     * (≠ Akteur) nie alleine auf »1« stehen kann. Das deckt sich mit dem
     * Selbst-Disable-Fall (separat oben getestet). Dieser Test ruft den
     * Controller daher direkt auf, um die Invariante isoliert von der
     * Firewall-Einschränkung nachzuweisen: ein Akteur ohne ROLE_ADMIN
     * (hier bewusst ein Moderator) versucht, den letzten aktiven Admin zu
     * deaktivieren.
     */
    public function testCannotDisableLastActiveAdmin(): void
    {
        $lastAdmin = $this->createAdmin('sole-admin@example.com', AdminRole::Admin);
        $actor = $this->createAdmin('bypass-actor@example.com', AdminRole::Moderator);

        $security = $this->createMock(Security::class);
        $security->expects(self::once())->method('getUser')->willReturn($actor);

        $controller = new UserDisableController();
        try {
            $controller(
                $lastAdmin->id,
                static::getContainer()->get(AdminUserRepository::class),
                $this->em,
                static::getContainer()->get(AuditLogger::class),
                $security,
                $this->freshStepUpGuard(),
            );
            self::fail('Erwartete ApiProblem-Exception blieb aus');
        } catch (ApiProblem $e) {
            self::assertSame(409, $e->getStatusCode());
            self::assertSame('Cannot disable the last active admin', $e->getMessage());
        }

        $this->em->refresh($lastAdmin);
        self::assertSame(AdminUserStatus::Active, $lastAdmin->status);
    }

    private function freshStepUpGuard(): StepUpGuard
    {
        $session = new Session(new MockArraySessionStorage());
        $session->set('_admin_verified_at', time());
        $request = new Request();
        $request->setSession($session);
        $requestStack = new RequestStack();
        $requestStack->push($request);

        return new StepUpGuard(new AdminSession($requestStack));
    }

    public function testEnableReactivatesDisabledUser(): void
    {
        $admin = $this->createAdmin('enable-chef@example.com', AdminRole::Admin);
        $this->loginWithCredentials($admin, 2);
        // Zweiter aktiver Admin, damit das Disable des Ziel-Accounts nicht
        // am Letzter-Admin-Schutz scheitert (Ziel ist hier ein Admin).
        $this->createAdmin('enable-chef-2@example.com', AdminRole::Admin);
        $target = $this->createAdmin('to-enable@example.com', AdminRole::Moderator);

        $this->client->request('POST', "/api/admin/users/{$target->id}/disable", server: $this->hdr());
        self::assertResponseStatusCodeSame(204);

        $this->client->request('POST', "/api/admin/users/{$target->id}/enable", server: $this->hdr());
        self::assertResponseStatusCodeSame(200);
        self::assertSame('active', $this->json()['status']);

        $this->em->refresh($target);
        self::assertSame(AdminUserStatus::Active, $target->status);
    }

    public function testEnableRejectedForNonDisabledUser(): void
    {
        $admin = $this->createAdmin('enable-chef3@example.com', AdminRole::Admin);
        $this->loginWithCredentials($admin, 2);
        $target = $this->createAdmin('already-active@example.com', AdminRole::Moderator, AdminUserStatus::Active);

        $this->client->request('POST', "/api/admin/users/{$target->id}/enable", server: $this->hdr());
        self::assertResponseStatusCodeSame(409);
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

    public function testDisableUnknownIdIs404(): void
    {
        $admin = $this->createAdmin('disable-404@example.com', AdminRole::Admin);
        $this->loginWithCredentials($admin, 2);

        $this->client->request('POST', '/api/admin/users/999999/disable', server: $this->hdr());
        self::assertResponseStatusCodeSame(404);
    }

    public function testEnableUnknownIdIs404(): void
    {
        $admin = $this->createAdmin('enable-404@example.com', AdminRole::Admin);
        $this->loginWithCredentials($admin, 2);

        $this->client->request('POST', '/api/admin/users/999999/enable', server: $this->hdr());
        self::assertResponseStatusCodeSame(404);
    }

    public function testReinviteUnknownIdIs404(): void
    {
        $admin = $this->createAdmin('reinvite-404@example.com', AdminRole::Admin);
        $this->loginWithCredentials($admin, 2);

        $this->client->request('POST', '/api/admin/users/999999/reinvite', server: $this->hdr());
        self::assertResponseStatusCodeSame(404);
    }

    public function testInviteInvalidJsonIs400(): void
    {
        $admin = $this->createAdmin('invite-invalid@example.com', AdminRole::Admin);
        $this->loginWithCredentials($admin, 2);

        $this->client->request('POST', '/api/admin/users', server: $this->hdr(), content: 'not-json');
        self::assertResponseStatusCodeSame(400);
    }
}
