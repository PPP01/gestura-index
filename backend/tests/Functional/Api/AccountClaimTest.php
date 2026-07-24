<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\Entry;
use App\Entity\EntryVersion;
use App\Entity\Submitter;
use App\Enum\EntryType;
use App\Service\PayloadAnalyzer;
use App\Tests\Functional\ApiTestCase;

final class AccountClaimTest extends ApiTestCase
{
    /** @return string Klartext-Konto-Token */
    private function createAccount(): string
    {
        $this->client->request('POST', '/api/account');
        self::assertResponseStatusCodeSame(201);

        return $this->json()['token'];
    }

    private function claim(string $accountToken, mixed $editToken): void
    {
        $this->client->request('POST', '/api/account/claims',
            server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $accountToken, 'CONTENT_TYPE' => 'application/json'],
            content: json_encode(['editToken' => $editToken], JSON_THROW_ON_ERROR));
    }

    /** Hängt einen minimalen Entry an den Submitter (für den entries-Zähler). */
    private function attachEntry(Submitter $submitter, string $formatId): void
    {
        $entry = new Entry($formatId, EntryType::Menu, $submitter);
        $payload = ['gesturaMenu' => 1, 'id' => $formatId, 'version' => '1.0.0', 'name' => 'X',
            'items' => [['id' => 'a', 'label' => 'A', 'action' => 'newTab']]];
        $version = new EntryVersion($entry, '1.0.0', $payload, (new PayloadAnalyzer())->contentHash($payload));
        $this->em->persist($entry);
        $this->em->persist($version);
        $this->em->flush();
    }

    public function testClaimLinksSubmitterAndCountsEntries(): void
    {
        $accountToken = $this->createAccount();
        [$submitter, $editToken] = $this->createSubmitterWithToken();
        $this->attachEntry($submitter, 'com.example.claim-a');

        $this->claim($accountToken, $editToken);
        self::assertResponseStatusCodeSame(200);
        self::assertSame(1, $this->json()['entries']);

        // em->refresh() auf dem ursprünglichen Objekt ist hier nicht robust:
        // claim() ist bereits der ZWEITE Client-Request in diesem Test (nach
        // createAccount()), und der KernelBrowser bootet ab dem zweiten
        // Request den Kernel neu (siehe UpdateTest-Konvention) – daher über
        // die Identity-Map neu laden statt refresh() auf dem Alt-Objekt.
        $this->em->clear();
        $reloaded = $this->em->getRepository(Submitter::class)->find($submitter->id);
        self::assertNotNull($reloaded->account);
    }

    public function testClaimIsIdempotentForSameAccount(): void
    {
        $accountToken = $this->createAccount();
        [, $editToken] = $this->createSubmitterWithToken();

        $this->claim($accountToken, $editToken);
        self::assertResponseStatusCodeSame(200);
        $this->claim($accountToken, $editToken);
        self::assertResponseStatusCodeSame(200);
    }

    public function testClaimBySecondAccountIs409(): void
    {
        $first = $this->createAccount();
        $second = $this->createAccount();
        [, $editToken] = $this->createSubmitterWithToken();

        $this->claim($first, $editToken);
        self::assertResponseStatusCodeSame(200);
        $this->claim($second, $editToken);
        self::assertResponseStatusCodeSame(409);
    }

    public function testClaimOfBannedSubmitterIs403(): void
    {
        $accountToken = $this->createAccount();
        [$submitter, $editToken] = $this->createSubmitterWithToken();
        $submitter->banned = true;
        $this->em->flush();

        $this->claim($accountToken, $editToken);
        self::assertResponseStatusCodeSame(403);
    }

    public function testClaimWithInvalidEditTokenIs401(): void
    {
        $accountToken = $this->createAccount();
        $this->claim($accountToken, 'gsti_' . str_repeat('a', 16) . '_' . str_repeat('b', 43));
        self::assertResponseStatusCodeSame(401);
    }

    public function testClaimWithMissingEditTokenIs400(): void
    {
        $accountToken = $this->createAccount();
        $this->claim($accountToken, null);
        self::assertResponseStatusCodeSame(400);
    }

    public function testClaimWithoutAccountTokenIs401(): void
    {
        $this->client->request('POST', '/api/account/claims',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['editToken' => 'x'], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(401);
    }
}
