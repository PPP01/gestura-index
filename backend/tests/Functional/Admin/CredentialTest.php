<?php
declare(strict_types=1);
namespace App\Tests\Functional\Admin;

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

    protected function loginAs(string $credentialId): void
    {
        $this->client->request('POST', '/api/admin/auth/options', server: $this->hdr());
        $this->client->request('POST', '/api/admin/auth/login', server: $this->hdr(),
            content: json_encode(['id' => $credentialId], JSON_THROW_ON_ERROR));
    }
}
