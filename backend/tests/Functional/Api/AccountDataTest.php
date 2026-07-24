<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\Account;
use App\Tests\Functional\ApiTestCase;

final class AccountDataTest extends ApiTestCase
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
        return ['HTTP_AUTHORIZATION' => 'Bearer ' . $token];
    }

    /** Lädt die Account-Entity zum Klartext-Token (Selector ist Index 1). */
    private function accountFor(string $token): Account
    {
        $selector = explode('_', $token)[1];
        $account = $this->em->getRepository(Account::class)->findOneBy(['tokenSelector' => $selector]);
        self::assertNotNull($account);

        return $account;
    }

    public function testWithoutTokenIs401(): void
    {
        $this->client->request('GET', '/api/account/data');
        self::assertResponseStatusCodeSame(401);
    }

    public function testWithInvalidTokenIs401(): void
    {
        $bogus = 'gacc_' . str_repeat('a', 16) . '_' . str_repeat('b', 43);
        $this->client->request('GET', '/api/account/data', server: $this->authHdr($bogus));
        self::assertResponseStatusCodeSame(401);
    }

    public function testEmptyAccountReturnsEmptySyncAsObject(): void
    {
        $token = $this->createAccount();

        $this->client->request('GET', '/api/account/data', server: $this->authHdr($token));
        self::assertResponseStatusCodeSame(200);

        $data = $this->json();
        self::assertArrayHasKey('createdAt', $data['account']);
        self::assertArrayHasKey('lastSeenAt', $data['account']);
        self::assertSame([], $data['submitters']);
        // Assoziatives json_decode kollabiert {} und [] — deshalb die Rohantwort prüfen:
        self::assertStringContainsString('"sync":{}', (string) $this->client->getResponse()->getContent());
    }

    public function testEndToEndExposesBlobCiphertext(): void
    {
        $token = $this->createAccount();

        // Blob über den echten Sync-PUT-Pfad anlegen:
        $this->client->request('PUT', '/api/account/sync/settings',
            server: $this->authHdr($token) + ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['baseVersion' => 0, 'ciphertext' => 'my-cipher'], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(200);

        $this->client->request('GET', '/api/account/data', server: $this->authHdr($token));
        self::assertResponseStatusCodeSame(200);
        self::assertSame('my-cipher', $this->json()['sync']['settings']['ciphertext']);
    }

    public function testLastSeenAtReportsPreviousValueThenTouches(): void
    {
        $token = $this->createAccount();

        // lastSeenAt deterministisch in die Vergangenheit setzen (ATOM ist
        // sekundengenau — ein Echtzeit-Delta wäre in derselben Sekunde flaky):
        $account = $this->accountFor($token);
        $account->lastSeenAt = new \DateTimeImmutable('2020-01-01T00:00:00+00:00');
        $this->em->flush();

        // Erster Abruf meldet den vorherigen Wert (2020) und berührt DANACH:
        $this->client->request('GET', '/api/account/data', server: $this->authHdr($token));
        self::assertResponseStatusCodeSame(200);
        self::assertStringStartsWith('2020-01-01', $this->json()['account']['lastSeenAt']);

        // Zweiter Abruf: nicht mehr 2020 ⇒ der erste Abruf hat berührt (zählt als Aktivität):
        $this->client->request('GET', '/api/account/data', server: $this->authHdr($token));
        self::assertResponseStatusCodeSame(200);
        self::assertStringStartsNotWith('2020-01-01', $this->json()['account']['lastSeenAt']);
    }
}
