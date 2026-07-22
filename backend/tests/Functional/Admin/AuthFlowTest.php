<?php
declare(strict_types=1);
namespace App\Tests\Functional\Admin;

use App\Entity\WebAuthnCredential;
use App\Enum\AdminRole;
use App\Enum\AdminUserStatus;

final class AuthFlowTest extends AdminTestCase
{
    public function testLoginThenMe(): void
    {
        $admin = $this->createAdmin('chef@example.com', AdminRole::Admin);
        $cred = new WebAuthnCredential($admin, 'cred-1', '{"id":"cred-1"}', 'Laptop');
        $this->em->persist($cred);
        $this->em->flush();

        // Options holen (setzt Challenge in Session)
        $this->client->request('POST', '/api/admin/auth/options', server: $this->hdr());
        self::assertResponseIsSuccessful();

        // Assertion posten (Fake liest 'id')
        $this->client->request('POST', '/api/admin/auth/login', server: $this->hdr(),
            content: json_encode(['id' => 'cred-1'], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(204);

        // me
        $this->client->request('GET', '/api/admin/auth/me', server: $this->hdr());
        self::assertResponseStatusCodeSame(200);
        self::assertSame('chef@example.com', $this->json()['email']);
        self::assertSame('admin', $this->json()['role']);
    }

    public function testMeWithoutSessionIs401(): void
    {
        $this->client->request('GET', '/api/admin/auth/me', server: $this->hdr());
        self::assertResponseStatusCodeSame(401);
    }

    public function testInactiveAccountCannotLogin(): void
    {
        $admin = $this->createAdmin('invited@example.com', AdminRole::Admin, AdminUserStatus::Invited);
        $cred = new WebAuthnCredential($admin, 'cred-x', '{"id":"cred-x"}', 'Laptop');
        $this->em->persist($cred);
        $this->em->flush();

        $this->client->request('POST', '/api/admin/auth/options', server: $this->hdr());
        self::assertResponseIsSuccessful();

        $this->client->request('POST', '/api/admin/auth/login', server: $this->hdr(),
            content: json_encode(['id' => 'cred-x'], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(403);

        // Kein Session-Login darf stattgefunden haben.
        $this->client->request('GET', '/api/admin/auth/me', server: $this->hdr());
        self::assertResponseStatusCodeSame(401);
    }

    public function testStepUpRejectsWrongUser(): void
    {
        $adminA = $this->createAdmin('a@example.com', AdminRole::Admin);
        $credA = new WebAuthnCredential($adminA, 'cred-a', '{"id":"cred-a"}', 'Laptop A');
        $this->em->persist($credA);

        $adminB = $this->createAdmin('b@example.com', AdminRole::Admin);
        $credB = new WebAuthnCredential($adminB, 'cred-b', '{"id":"cred-b"}', 'Laptop B');
        $this->em->persist($credB);
        $this->em->flush();

        // Als A einloggen.
        $this->client->request('POST', '/api/admin/auth/options', server: $this->hdr());
        self::assertResponseIsSuccessful();
        $this->client->request('POST', '/api/admin/auth/login', server: $this->hdr(),
            content: json_encode(['id' => 'cred-a'], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(204);

        // Step-up mit Bs Credential muss abgelehnt werden.
        $this->client->request('POST', '/api/admin/stepup', server: $this->hdr(),
            content: json_encode(['id' => 'cred-b'], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(403);
    }


    public function testLogoutInvalidatesSession(): void
    {
        $admin = $this->createAdmin('chef2@example.com', AdminRole::Admin);
        $cred = new WebAuthnCredential($admin, 'cred-2', '{"id":"cred-2"}', 'Laptop');
        $this->em->persist($cred);
        $this->em->flush();

        $this->client->request('POST', '/api/admin/auth/options', server: $this->hdr());
        self::assertResponseIsSuccessful();
        $this->client->request('POST', '/api/admin/auth/login', server: $this->hdr(),
            content: json_encode(['id' => 'cred-2'], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(204);

        $this->client->request('GET', '/api/admin/auth/me', server: $this->hdr());
        self::assertResponseStatusCodeSame(200);

        $this->client->request('POST', '/api/admin/auth/logout', server: $this->hdr());
        self::assertResponseStatusCodeSame(204);

        $this->client->request('GET', '/api/admin/auth/me', server: $this->hdr());
        self::assertResponseStatusCodeSame(401);
    }
}
