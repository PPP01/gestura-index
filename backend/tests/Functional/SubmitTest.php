<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Entry;
use App\Enum\EntryStatus;

final class SubmitTest extends ApiTestCase
{
    public function testAnonymousSubmissionCreatesPendingEntryAndReturnsToken(): void
    {
        $this->api('POST', '/api/v1/entries', [
            'payload' => $this->menuPayload(),
            'categories' => ['shopping', 'other'],
            'tags' => ['Shop ', 'BEISPIEL'],
        ]);

        self::assertResponseStatusCodeSame(201);
        $data = $this->json();
        self::assertSame('com.example.shop', $data['formatId']);
        self::assertSame('pending', $data['status']);
        self::assertMatchesRegularExpression('/^gsti_[0-9a-f]{16}_[A-Za-z0-9_-]{43}$/', $data['editToken']);

        $entry = $this->em->getRepository(Entry::class)->findOneBy(['formatId' => 'com.example.shop']);
        self::assertSame(EntryStatus::Pending, $entry->status);
        self::assertSame(['shop', 'beispiel'], $entry->tags);
        self::assertSame(['example.com'], $entry->domains);
        self::assertStringContainsString('beispiel-shop', $entry->searchText);
    }

    public function testTokenReuseAttachesToSameSubmitterAndReturnsNoToken(): void
    {
        [$submitter, $token] = $this->createSubmitterWithToken();

        $this->api('POST', '/api/v1/entries', [
            'payload' => $this->enginePayload(),
            'categories' => ['search'],
        ], token: $token);

        self::assertResponseStatusCodeSame(201);
        self::assertArrayNotHasKey('editToken', $this->json());

        $entry = $this->em->getRepository(Entry::class)->findOneBy(['formatId' => 'com.example.search']);
        self::assertSame($submitter->id, $entry->submitter->id);
    }

    public function testInvalidTokenYields401(): void
    {
        $this->api('POST', '/api/v1/entries', [
            'payload' => $this->menuPayload(),
            'categories' => ['shopping'],
        ], token: 'gsti_' . str_repeat('0', 16) . '_' . str_repeat('A', 43));

        self::assertResponseStatusCodeSame(401);
    }

    public function testInvalidPayloadYields400WithErrors(): void
    {
        $payload = $this->menuPayload();
        $payload['items'][0]['customUrl'] = 'javascript:alert(1)';

        $this->api('POST', '/api/v1/entries', ['payload' => $payload, 'categories' => ['shopping']]);

        self::assertResponseStatusCodeSame(400);
        self::assertNotEmpty($this->json()['errors']);
    }

    public function testBadMetadataYields400(): void
    {
        $this->api('POST', '/api/v1/entries', ['payload' => $this->menuPayload(), 'categories' => []]);
        self::assertResponseStatusCodeSame(400);

        $this->api('POST', '/api/v1/entries', ['payload' => $this->menuPayload(), 'categories' => ['a', 'b', 'c', 'd']]);
        self::assertResponseStatusCodeSame(400);

        $this->api('POST', '/api/v1/entries', ['payload' => $this->menuPayload(), 'categories' => ['quatsch']]);
        self::assertResponseStatusCodeSame(400);
    }

    public function testTakenFormatIdYields409(): void
    {
        $this->createPublishedEntry('com.example.shop');

        $this->api('POST', '/api/v1/entries', ['payload' => $this->menuPayload(), 'categories' => ['shopping']]);
        self::assertResponseStatusCodeSame(409);
    }

    public function testDuplicateContentUnderNewIdYields409(): void
    {
        $this->createPublishedEntry('com.example.original');

        // Identischer Inhalt unter neuer Kennung: contentHash ignoriert
        // id/version, die Kollision mit dem fremden Entry wird erkannt.
        $this->api('POST', '/api/v1/entries', [
            'payload' => $this->menuPayload(['id' => 'com.example.kopie']),
            'categories' => ['shopping'],
        ]);
        self::assertResponseStatusCodeSame(409);
    }

    public function testOversizedBodyYields413(): void
    {
        $payload = $this->menuPayload(['description' => ['en' => 'x']]);
        $body = json_encode(['payload' => $payload, 'categories' => ['shopping']], JSON_THROW_ON_ERROR);
        $body = substr_replace($body, str_repeat(' ', 132000), -1, 0);

        $this->client->request('POST', '/api/v1/entries', server: ['CONTENT_TYPE' => 'application/json'], content: $body);
        self::assertResponseStatusCodeSame(413);
    }

    public function testBannedSubmitterYields403(): void
    {
        [$submitter, $token] = $this->createSubmitterWithToken();
        $submitter->banned = true;
        $this->em->flush();

        $this->api('POST', '/api/v1/entries', ['payload' => $this->menuPayload(), 'categories' => ['shopping']], token: $token);
        self::assertResponseStatusCodeSame(403);
    }
}
