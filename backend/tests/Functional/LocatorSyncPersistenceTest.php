<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Api\SyncContract;
use App\Entity\SyncState;

/**
 * Sichert die Ablage der Sync-Stände gegen die ECHTE Datenbank ab – nicht
 * gegen die Mapping-Absicht. Zwei Dinge könnten hier still schiefgehen: eine
 * zu kleine Spalte für einen maximal großen Envelope und ein Klartext-Locator,
 * der doch irgendwo landet.
 */
final class LocatorSyncPersistenceTest extends ApiTestCase
{
    /**
     * Ein 512-KiB-Envelope muss durch die Spalte passen. MySQL-TEXT fasst nur
     * 64 KiB – die Spalte MUSS MEDIUMTEXT sein, sonst wird der Stand
     * stillschweigend abgeschnitten und der Client verwirft ihn danach als
     * unentschlüsselbar.
     */
    public function testAMaximumSizedPayloadSurvivesARoundTrip(): void
    {
        $payload = str_repeat('A', SyncContract::MAX_PAYLOAD_BYTES);
        $state = new SyncState(SyncContract::locatorHash(self::SYNC_LOCATOR), self::SYNC_STATE_ID, 'bWV0YQ==', $payload);

        $this->em->persist($state);
        $this->em->flush();
        $this->em->clear();

        $found = $this->syncStates()->findOneByLocatorAndState(SyncContract::locatorHash(self::SYNC_LOCATOR), self::SYNC_STATE_ID);

        self::assertNotNull($found);
        self::assertSame(SyncContract::MAX_PAYLOAD_BYTES, \strlen($found->payload));
        self::assertSame(SyncContract::MAX_PAYLOAD_BYTES, $found->sizeBytes);
    }

    /** Der Klartext-Locator darf nirgends in der Zeile stehen. */
    public function testOnlyTheHashIsStored(): void
    {
        $state = new SyncState(SyncContract::locatorHash(self::SYNC_LOCATOR), self::SYNC_STATE_ID, 'bWV0YQ==', 'cGF5bG9hZA==');
        $this->em->persist($state);
        $this->em->flush();

        $row = $this->em->getConnection()
            ->fetchAssociative('SELECT * FROM sync_state WHERE state_id = ?', [self::SYNC_STATE_ID]);

        self::assertIsArray($row);
        self::assertNotContains(self::SYNC_LOCATOR, array_map(strval(...), $row));
        self::assertSame(hash('sha256', self::SYNC_LOCATOR), $row['locator_hash']);
    }

    /** payloadHash und sizeBytes werden abgeleitet, nie von außen gesetzt. */
    public function testHashAndSizeAreDerivedFromTheBlobs(): void
    {
        $payload = base64_encode('irgendwelche Bytes');
        $state = new SyncState(SyncContract::locatorHash(self::SYNC_LOCATOR), self::SYNC_STATE_ID, 'bWV0YQ==', $payload);

        self::assertSame(SyncContract::payloadHash($payload), $state->payloadHash);
        self::assertSame(\strlen($payload), $state->sizeBytes);

        $neu = base64_encode('andere Bytes');
        $state->replaceBlobs('bmV1', $neu);

        self::assertSame(SyncContract::payloadHash($neu), $state->payloadHash);
        self::assertSame(\strlen($neu), $state->sizeBytes);
    }

    /** Zwei Stände gleicher stateId unter VERSCHIEDENEN Locators sind erlaubt. */
    public function testTheSameStateIdMayExistUnderTwoLocators(): void
    {
        $a = new SyncState(SyncContract::locatorHash('A' . str_repeat('a', 42)), self::SYNC_STATE_ID, 'bQ==', 'cA==');
        $b = new SyncState(SyncContract::locatorHash('B' . str_repeat('b', 42)), self::SYNC_STATE_ID, 'bQ==', 'cA==');

        $this->em->persist($a);
        $this->em->persist($b);
        $this->em->flush();

        self::assertNotSame($a->id, $b->id);
    }

}
