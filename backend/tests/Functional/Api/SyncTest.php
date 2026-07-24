<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Tests\Functional\ApiTestCase;

final class SyncTest extends ApiTestCase
{
    /** @return string Klartext-Token */
    private function createAccount(): string
    {
        $this->client->request('POST', '/api/account');
        self::assertResponseStatusCodeSame(201);

        return $this->json()['token'];
    }

    /** @return array<string, string> */
    private function authHdr(string $token): array
    {
        return ['HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'CONTENT_TYPE' => 'application/json'];
    }

    private function putBlob(string $token, string $collection, int $baseVersion, string $ciphertext): void
    {
        $this->client->request('PUT', '/api/account/sync/' . $collection, server: $this->authHdr($token),
            content: json_encode(['baseVersion' => $baseVersion, 'ciphertext' => $ciphertext], JSON_THROW_ON_ERROR));
    }

    public function testPutCreatesAndIncrementsVersion(): void
    {
        $token = $this->createAccount();

        $this->putBlob($token, 'settings', 0, 'cipher-v1');
        self::assertResponseStatusCodeSame(200);
        self::assertSame(1, $this->json()['version']);

        $this->putBlob($token, 'settings', 1, 'cipher-v2');
        self::assertResponseStatusCodeSame(200);
        self::assertSame(2, $this->json()['version']);
    }

    public function testPutWithStaleBaseVersionIs409AndKeepsBlob(): void
    {
        $token = $this->createAccount();
        $this->putBlob($token, 'settings', 0, 'cipher-v1');
        $this->putBlob($token, 'settings', 1, 'cipher-v2');

        // Veraltete baseVersion (1, aktuell ist 2) → 409 mit aktueller Version:
        $this->putBlob($token, 'settings', 1, 'cipher-stale');
        self::assertResponseStatusCodeSame(409);
        self::assertSame(2, $this->json()['version']);

        // Die Nachprüfung, dass der Blob unverändert bleibt, folgt in Task 3
        // (GET /api/account/sync/{collection} existiert dort erst).
    }

    public function testPutUnknownCollectionIs400(): void
    {
        $token = $this->createAccount();
        $this->putBlob($token, 'bookmarks', 0, 'x');
        self::assertResponseStatusCodeSame(400);
    }

    public function testPutInvalidFieldsAre400(): void
    {
        $token = $this->createAccount();

        $this->client->request('PUT', '/api/account/sync/settings', server: $this->authHdr($token),
            content: json_encode(['baseVersion' => -1, 'ciphertext' => 'x'], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(400);

        $this->client->request('PUT', '/api/account/sync/settings', server: $this->authHdr($token),
            content: json_encode(['baseVersion' => 0], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(400);

        $this->client->request('PUT', '/api/account/sync/settings', server: $this->authHdr($token),
            content: 'not-json');
        self::assertResponseStatusCodeSame(400);
    }

    public function testPutOversizedCiphertextIs413(): void
    {
        $token = $this->createAccount();
        $this->putBlob($token, 'settings', 0, str_repeat('a', 262145));
        self::assertResponseStatusCodeSame(413);
    }

    public function testPutWithoutTokenIs401(): void
    {
        $this->client->request('PUT', '/api/account/sync/settings', server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['baseVersion' => 0, 'ciphertext' => 'x'], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(401);
    }
}
