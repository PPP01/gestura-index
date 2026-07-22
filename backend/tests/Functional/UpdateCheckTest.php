<?php

declare(strict_types=1);

namespace App\Tests\Functional;

final class UpdateCheckTest extends ApiTestCase
{
    public function testReportsOnlyNewerVersions(): void
    {
        $entry = $this->createPublishedEntry('com.example.shop', ['version' => '2.1.0']);
        $entry->deprecated = true;
        $entry->successorFormatId = 'com.example.shop2';
        $this->createPublishedEntry('com.example.aktuell');
        $this->em->flush();

        $this->api('POST', '/api/v1/entries/updates', ['entries' => [
            ['id' => 'com.example.shop', 'version' => '1.0.0'],
            ['id' => 'com.example.aktuell', 'version' => '1.0.0'],
            ['id' => 'com.example.unbekannt', 'version' => '1.0.0'],
        ]]);

        self::assertResponseIsSuccessful();
        $updates = $this->json()['updates'];
        self::assertCount(1, $updates);
        self::assertSame('com.example.shop', $updates[0]['id']);
        self::assertSame('2.1.0', $updates[0]['latestVersion']);
        self::assertTrue($updates[0]['deprecated']);
        self::assertSame('com.example.shop2', $updates[0]['successorFormatId']);
    }

    public function testRejectsOversizedOrMalformedBody(): void
    {
        $many = array_fill(0, 201, ['id' => 'com.example.x', 'version' => '1.0.0']);
        $this->api('POST', '/api/v1/entries/updates', ['entries' => $many]);
        self::assertResponseStatusCodeSame(400);

        $this->api('POST', '/api/v1/entries/updates', ['entries' => 'quatsch']);
        self::assertResponseStatusCodeSame(400);
    }

    public function testRejectsMalformedJsonBody(): void
    {
        $this->client->request('POST', '/api/v1/entries/updates',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{kaputtes json',
        );
        self::assertResponseStatusCodeSame(400);
    }

    public function testSkipsInvalidIndividualEntriesButKeepsCheckUsable(): void
    {
        $this->createPublishedEntry('com.example.shop', ['version' => '2.0.0']);

        $this->api('POST', '/api/v1/entries/updates', ['entries' => [
            ['id' => 'com.example.shop', 'version' => 'keine-semver'], // fehlerhaft: kein SemVer
            ['id' => 'com.example.shop'], // fehlerhaft: version fehlt
            ['version' => '1.0.0'], // fehlerhaft: id fehlt
        ]]);

        self::assertResponseIsSuccessful();
        self::assertSame([], $this->json()['updates']); // fehlerhafte Posten stillschweigend übersprungen
    }
}
