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

        // Nachprüfung, dass der Blob unverändert bleibt:
        $this->client->request('GET', '/api/account/sync/settings', server: $this->authHdr($token));
        self::assertSame('cipher-v2', $this->json()['ciphertext']);
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

    public function testGetReturnsBlobAndEmptySlotIs404(): void
    {
        $token = $this->createAccount();
        $this->client->request('GET', '/api/account/sync/settings', server: $this->authHdr($token));
        self::assertResponseStatusCodeSame(404);

        $this->putBlob($token, 'settings', 0, 'cipher-v1');
        $this->client->request('GET', '/api/account/sync/settings', server: $this->authHdr($token));
        self::assertResponseStatusCodeSame(200);
        self::assertSame(1, $this->json()['version']);
        self::assertSame('cipher-v1', $this->json()['ciphertext']);
    }

    public function testOverviewListsVersionsWithoutCiphertext(): void
    {
        $token = $this->createAccount();

        $this->client->request('GET', '/api/account/sync', server: $this->authHdr($token));
        self::assertResponseStatusCodeSame(200);
        self::assertSame([], (array) $this->json()['collections']);

        $this->putBlob($token, 'settings', 0, 'cipher-set');
        $this->putBlob($token, 'menus', 0, 'cipher-menus');

        $this->client->request('GET', '/api/account/sync', server: $this->authHdr($token));
        $collections = $this->json()['collections'];
        self::assertSame(1, $collections['settings']['version']);
        self::assertSame(\strlen('cipher-menus'), $collections['menus']['size']);
        self::assertArrayHasKey('updatedAt', $collections['settings']);
        self::assertArrayNotHasKey('ciphertext', $collections['settings']);
    }

    public function testDeleteClearsSlotAndSecondDeleteIs404(): void
    {
        $token = $this->createAccount();
        $this->putBlob($token, 'engines', 0, 'cipher-e');

        $this->client->request('DELETE', '/api/account/sync/engines', server: $this->authHdr($token));
        self::assertResponseStatusCodeSame(204);

        $this->client->request('DELETE', '/api/account/sync/engines', server: $this->authHdr($token));
        self::assertResponseStatusCodeSame(404);

        // Nach dem Löschen beginnt der Slot wieder bei baseVersion 0:
        $this->putBlob($token, 'engines', 0, 'cipher-neu');
        self::assertResponseStatusCodeSame(200);
        self::assertSame(1, $this->json()['version']);
    }

    public function testGetUnknownCollectionIs400(): void
    {
        $token = $this->createAccount();
        $this->client->request('GET', '/api/account/sync/bookmarks', server: $this->authHdr($token));
        self::assertResponseStatusCodeSame(400);
    }
}
