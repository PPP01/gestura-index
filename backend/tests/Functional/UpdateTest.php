<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Entry;
use App\Entity\EntryVersion;
use App\Enum\EntryStatus;
use App\Enum\VersionStatus;

final class UpdateTest extends ApiTestCase
{
    public function testUpdateWithoutTransformGoesLiveImmediately(): void
    {
        [$submitter, $token] = $this->createSubmitterWithToken();
        $this->createPublishedEntry('com.example.shop', submitter: $submitter);

        $this->api('PUT', '/api/v1/entries/com.example.shop', [
            'payload' => $this->menuPayload(['version' => '1.1.0', 'name' => 'Neuer Name']),
            'changelog' => 'Neuer Eintrag ergänzt',
        ], token: $token);

        self::assertResponseIsSuccessful();
        self::assertSame('approved', $this->json()['versionStatus']);

        $this->em->clear();
        $entry = $this->em->getRepository(Entry::class)->findOneBy(['formatId' => 'com.example.shop']);
        self::assertSame('1.1.0', $entry->currentVersion->semver);
        self::assertStringContainsString('neuer name', $entry->searchText);
    }

    public function testUpdateWithTransformGoesToQueue(): void
    {
        [$submitter, $token] = $this->createSubmitterWithToken();
        // createPublishedEntry baut einen Menü-Payload; für den Engine-Fall
        // Typ und Payload des angelegten Entrys direkt umbiegen:
        $entry = $this->createPublishedEntry('com.example.search', submitter: $submitter);
        $entry->type = \App\Enum\EntryType::Engine;
        $entry->currentVersion->payload = $this->enginePayload(['id' => 'com.example.search']);
        $this->em->flush();

        $this->api('PUT', '/api/v1/entries/com.example.search', [
            'payload' => $this->enginePayload(['id' => 'com.example.search', 'version' => '1.1.0', 'transformEnabled' => true, 'transformCode' => 'return input.toUpperCase();']),
        ], token: $token);

        self::assertResponseIsSuccessful();
        self::assertSame('pending', $this->json()['versionStatus']);

        $this->em->clear();
        $entry = $this->em->getRepository(Entry::class)->findOneBy(['formatId' => 'com.example.search']);
        self::assertSame('1.0.0', $entry->currentVersion->semver); // bleibt auf alter Version
    }

    public function testNonMonotonicSemverYields409(): void
    {
        [$submitter, $token] = $this->createSubmitterWithToken();
        $this->createPublishedEntry('com.example.shop', ['version' => '2.0.0'], $submitter);

        $this->api('PUT', '/api/v1/entries/com.example.shop', [
            'payload' => $this->menuPayload(['version' => '1.9.0']),
        ], token: $token);

        self::assertResponseStatusCodeSame(409);
    }

    public function testForeignTokenYields403AndMissingTokenYields401(): void
    {
        $this->createPublishedEntry('com.example.shop');
        [, $foreignToken] = $this->createSubmitterWithToken();

        $this->api('PUT', '/api/v1/entries/com.example.shop', ['payload' => $this->menuPayload(['version' => '1.1.0'])], token: $foreignToken);
        self::assertResponseStatusCodeSame(403);

        $this->api('PUT', '/api/v1/entries/com.example.shop', ['payload' => $this->menuPayload(['version' => '1.1.0'])]);
        self::assertResponseStatusCodeSame(401);
    }

    public function testPendingEntryUpdateReplacesPendingVersion(): void
    {
        [$submitter, $token] = $this->createSubmitterWithToken();
        $entry = $this->createPublishedEntry('com.example.shop', submitter: $submitter);
        $entry->status = EntryStatus::Pending;
        $entry->currentVersion->status = VersionStatus::Pending;
        $entry->currentVersion = null;
        $this->em->flush();

        $this->api('PUT', '/api/v1/entries/com.example.shop', [
            'payload' => $this->menuPayload(['version' => '1.0.1']),
        ], token: $token);

        self::assertResponseIsSuccessful();
        $this->em->clear();
        $versions = $this->em->getRepository(EntryVersion::class)->findBy([], ['id' => 'ASC']);
        $pending = array_filter($versions, static fn (EntryVersion $v): bool => $v->status === VersionStatus::Pending);
        self::assertCount(1, $pending); // alte pending-Version wurde ersetzt
        self::assertSame('1.0.1', array_values($pending)[0]->semver);
    }

    public function testHiddenYields409AndDeletedYields404(): void
    {
        [$submitter, $token] = $this->createSubmitterWithToken();
        $hidden = $this->createPublishedEntry('com.example.hidden', submitter: $submitter);
        $hidden->status = EntryStatus::Hidden;
        $deleted = $this->createPublishedEntry('com.example.deleted', submitter: $submitter);
        $deleted->status = EntryStatus::Deleted;
        $this->em->flush();

        $this->api('PUT', '/api/v1/entries/com.example.hidden', ['payload' => $this->menuPayload(['id' => 'com.example.hidden', 'version' => '1.1.0'])], token: $token);
        self::assertResponseStatusCodeSame(409);

        $this->api('PUT', '/api/v1/entries/com.example.deleted', ['payload' => $this->menuPayload(['id' => 'com.example.deleted', 'version' => '1.1.0'])], token: $token);
        self::assertResponseStatusCodeSame(404);
    }

    public function testPayloadIdMismatchYields400(): void
    {
        [$submitter, $token] = $this->createSubmitterWithToken();
        $this->createPublishedEntry('com.example.shop', submitter: $submitter);

        $this->api('PUT', '/api/v1/entries/com.example.shop', [
            'payload' => $this->menuPayload(['id' => 'com.example.anders', 'version' => '1.1.0']),
        ], token: $token);

        self::assertResponseStatusCodeSame(400);
    }
}
