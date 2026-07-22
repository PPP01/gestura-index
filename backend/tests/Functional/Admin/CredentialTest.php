<?php
declare(strict_types=1);
namespace App\Tests\Functional\Admin;

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

    protected function loginAs(string $credentialId): void
    {
        $this->client->request('POST', '/api/admin/auth/options', server: $this->hdr());
        $this->client->request('POST', '/api/admin/auth/login', server: $this->hdr(),
            content: json_encode(['id' => $credentialId], JSON_THROW_ON_ERROR));
    }

    /** @return array<string,string> */
    protected function hdr(): array
    {
        return ['CONTENT_TYPE' => 'application/json', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'];
    }
}
