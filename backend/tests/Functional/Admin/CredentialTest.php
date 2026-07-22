<?php
declare(strict_types=1);
namespace App\Tests\Functional\Admin;

use App\Entity\AdminUser;
use App\Entity\AuditLogEntry;
use App\Entity\WebAuthnCredential;
use App\Enum\AdminRole;

final class CredentialTest extends AdminTestCase
{
    public function testAddSecondAndRemoveGuard(): void
    {
        $admin = $this->createAdmin('chef@example.com', AdminRole::Admin);
        $this->em->persist(new WebAuthnCredential($admin, 'cred-1', '{"id":"cred-1"}', 'Laptop'));
        $this->em->flush();
        $this->loginAs('cred-1');

        // zweiten Passkey hinzufügen
        $this->client->request('POST', '/api/admin/credentials/options', server: $this->hdr());
        self::assertResponseIsSuccessful();
        $this->client->request('POST', '/api/admin/credentials', server: $this->hdr(),
            content: json_encode(['id' => 'cred-2', 'label' => 'Handy'], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);

        // Liste zeigt 2
        $this->client->request('GET', '/api/admin/credentials', server: $this->hdr());
        self::assertCount(2, $this->json());

        // einen entfernen (bleibt ... < 2) → 409; hier: von 2 auf 1 => 409
        $credId = $this->json()[0]['id'];
        $this->client->request('POST', "/api/admin/credentials/{$credId}/remove", server: $this->hdr());
        self::assertResponseStatusCodeSame(409);
    }

    public function testAddCredentialWritesAuditLog(): void
    {
        $admin = $this->createAdmin('chef-audit@example.com', AdminRole::Admin);
        $this->em->persist(new WebAuthnCredential($admin, 'cred-audit-1', '{"id":"cred-audit-1"}', 'Laptop'));
        $this->em->flush();
        $this->loginAs('cred-audit-1');

        $this->client->request('POST', '/api/admin/credentials/options', server: $this->hdr());
        self::assertResponseIsSuccessful();
        $this->client->request('POST', '/api/admin/credentials', server: $this->hdr(),
            content: json_encode(['id' => 'cred-audit-2', 'label' => 'Handy'], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);
        $credId = $this->json()['id'];

        $entry = $this->em->getRepository(AuditLogEntry::class)->findOneBy([
            'action' => 'credential.add',
            'targetId' => (string) $credId,
        ]);
        self::assertNotNull($entry);
        self::assertSame($admin->id, $entry->actor->id);
    }

    public function testCredentialActionsAreScopedToOwner(): void
    {
        $adminA = $this->createAdmin('owner-a@example.com', AdminRole::Admin);
        $this->em->persist(new WebAuthnCredential($adminA, 'cred-a1', '{"id":"cred-a1"}', 'A Laptop'));
        $this->em->flush();

        $adminB = $this->createAdmin('owner-b@example.com', AdminRole::Admin);
        $credB1 = new WebAuthnCredential($adminB, 'cred-b1', '{"id":"cred-b1"}', 'B Laptop');
        $credB2 = new WebAuthnCredential($adminB, 'cred-b2', '{"id":"cred-b2"}', 'B Handy');
        $this->em->persist($credB1);
        $this->em->persist($credB2);
        $this->em->flush();

        // Als A einloggen, dann gegen Bs Credential arbeiten.
        $this->loginAs('cred-a1');

        $this->client->request('PATCH', "/api/admin/credentials/{$credB1->id}", server: $this->hdr(),
            content: json_encode(['label' => 'Gekapert'], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(404);

        $this->client->request('POST', "/api/admin/credentials/{$credB1->id}/remove", server: $this->hdr());
        self::assertResponseStatusCodeSame(404);
    }

    public function testRemoveSucceedsWhenMoreThanTwoCredentialsRemain(): void
    {
        $admin = $this->createAdmin('multi-remove@example.com', AdminRole::Admin);
        $this->em->persist(new WebAuthnCredential($admin, 'cred-r1', '{"id":"cred-r1"}', 'Laptop'));
        $this->em->persist(new WebAuthnCredential($admin, 'cred-r2', '{"id":"cred-r2"}', 'Handy'));
        $extra = new WebAuthnCredential($admin, 'cred-r3', '{"id":"cred-r3"}', 'Tablet');
        $this->em->persist($extra);
        $this->em->flush();
        $this->loginAs('cred-r1');

        $this->client->request('POST', "/api/admin/credentials/{$extra->id}/remove", server: $this->hdr());
        self::assertResponseStatusCodeSame(204);

        $this->em->clear();
        $reloaded = $this->em->getRepository(AdminUser::class)->find($admin->id);
        self::assertCount(2, $reloaded->credentials);

        $entry = $this->em->getRepository(AuditLogEntry::class)->findOneBy([
            'action' => 'credential.remove',
            'targetId' => (string) $extra->id,
        ]);
        self::assertNotNull($entry);
    }

    public function testRemoveUnknownIdIs404(): void
    {
        $admin = $this->createAdmin('remove-404@example.com', AdminRole::Admin);
        $this->em->persist(new WebAuthnCredential($admin, 'cred-rm404', '{"id":"cred-rm404"}', 'Laptop'));
        $this->em->flush();
        $this->loginAs('cred-rm404');

        $this->client->request('POST', '/api/admin/credentials/999999/remove', server: $this->hdr());
        self::assertResponseStatusCodeSame(404);
    }

    public function testAddCredentialInvalidJsonIs400(): void
    {
        $admin = $this->createAdmin('add-invalid@example.com', AdminRole::Admin);
        $this->em->persist(new WebAuthnCredential($admin, 'cred-addinv-1', '{"id":"cred-addinv-1"}', 'Laptop'));
        $this->em->flush();
        $this->loginAs('cred-addinv-1');

        $this->client->request('POST', '/api/admin/credentials/options', server: $this->hdr());
        self::assertResponseIsSuccessful();
        $this->client->request('POST', '/api/admin/credentials', server: $this->hdr(), content: 'not-json');
        self::assertResponseStatusCodeSame(400);
    }

    public function testLabelRenameSucceeds(): void
    {
        $admin = $this->createAdmin('label-owner@example.com', AdminRole::Admin);
        $cred = new WebAuthnCredential($admin, 'cred-label-1', '{"id":"cred-label-1"}', 'Laptop');
        $this->em->persist($cred);
        $this->em->flush();
        $this->loginAs('cred-label-1');

        $this->client->request('PATCH', "/api/admin/credentials/{$cred->id}", server: $this->hdr(),
            content: json_encode(['label' => 'Neuer Name'], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(200);
        self::assertSame('Neuer Name', $this->json()['label']);

        $this->em->clear();
        $reloaded = $this->em->getRepository(WebAuthnCredential::class)->find($cred->id);
        self::assertSame('Neuer Name', $reloaded->label);
    }

    public function testLabelRenameMissingLabelIs400(): void
    {
        $admin = $this->createAdmin('label-owner2@example.com', AdminRole::Admin);
        $cred = new WebAuthnCredential($admin, 'cred-label-2', '{"id":"cred-label-2"}', 'Laptop');
        $this->em->persist($cred);
        $this->em->flush();
        $this->loginAs('cred-label-2');

        $this->client->request('PATCH', "/api/admin/credentials/{$cred->id}", server: $this->hdr(),
            content: json_encode(['label' => ''], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(400);
    }

    public function testLabelRenameUnknownIdIs404(): void
    {
        $admin = $this->createAdmin('label-owner3@example.com', AdminRole::Admin);
        $cred = new WebAuthnCredential($admin, 'cred-label-3', '{"id":"cred-label-3"}', 'Laptop');
        $this->em->persist($cred);
        $this->em->flush();
        $this->loginAs('cred-label-3');

        $this->client->request('PATCH', '/api/admin/credentials/999999', server: $this->hdr(),
            content: json_encode(['label' => 'X'], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(404);
    }

    public function testLabelRenameInvalidJsonIs400(): void
    {
        $admin = $this->createAdmin('label-owner4@example.com', AdminRole::Admin);
        $cred = new WebAuthnCredential($admin, 'cred-label-4', '{"id":"cred-label-4"}', 'Laptop');
        $this->em->persist($cred);
        $this->em->flush();
        $this->loginAs('cred-label-4');

        $this->client->request('PATCH', "/api/admin/credentials/{$cred->id}", server: $this->hdr(),
            content: 'not-json');
        self::assertResponseStatusCodeSame(400);
    }

    protected function loginAs(string $credentialId): void
    {
        $this->client->request('POST', '/api/admin/auth/options', server: $this->hdr());
        $this->client->request('POST', '/api/admin/auth/login', server: $this->hdr(),
            content: json_encode(['id' => $credentialId], JSON_THROW_ON_ERROR));
    }
}
