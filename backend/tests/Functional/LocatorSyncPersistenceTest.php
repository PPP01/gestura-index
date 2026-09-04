<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Api\SyncContract;
use App\Entity\SyncState;
use App\Repository\SyncStateRepository;

/**
 * Sichert die Ablage der Sync-Stände gegen die ECHTE Datenbank ab – nicht
 * gegen die Mapping-Absicht. Zwei Dinge könnten hier still schiefgehen: eine
 * zu kleine Spalte für einen maximal großen Envelope und ein Klartext-Locator,
 * der doch irgendwo landet.
 */
final class LocatorSyncPersistenceTest extends ApiTestCase
{
    private const LOCATOR = 'zoogXw2lwmt_ZqFnRu-lFOWYxyJaU2kpxfunpy3Umsk';
    private const STATE_ID = '0123456789abcdef0123456789abcdef';

    /**
     * Ein 512-KiB-Envelope muss durch die Spalte passen. MySQL-TEXT fasst nur
     * 64 KiB – die Spalte MUSS MEDIUMTEXT sein, sonst wird der Stand
     * stillschweigend abgeschnitten und der Client verwirft ihn danach als
     * unentschlüsselbar.
     */
    public function testAMaximumSizedPayloadSurvivesARoundTrip(): void
    {
        $payload = str_repeat('A', SyncContract::MAX_PAYLOAD_BYTES);
        $state = new SyncState(SyncContract::locatorHash(self::LOCATOR), self::STATE_ID, 'bWV0YQ==', $payload);

        $this->em->persist($state);
        $this->em->flush();
        $this->em->clear();

        $repo = static::getContainer()->get(SyncStateRepository::class);
        $found = $repo->findOneByLocatorAndState(SyncContract::locatorHash(self::LOCATOR), self::STATE_ID);

        self::assertNotNull($found);
        self::assertSame(SyncContract::MAX_PAYLOAD_BYTES, \strlen($found->payload));
        self::assertSame(SyncContract::MAX_PAYLOAD_BYTES, $found->sizeBytes);
    }

    /** Der Klartext-Locator darf nirgends in der Zeile stehen. */
    public function testOnlyTheHashIsStored(): void
    {
        $state = new SyncState(SyncContract::locatorHash(self::LOCATOR), self::STATE_ID, 'bWV0YQ==', 'cGF5bG9hZA==');
        $this->em->persist($state);
        $this->em->flush();

        $row = $this->em->getConnection()
            ->fetchAssociative('SELECT * FROM sync_state WHERE state_id = ?', [self::STATE_ID]);

        self::assertIsArray($row);
        self::assertNotContains(self::LOCATOR, array_map(strval(...), $row));
        self::assertSame(hash('sha256', self::LOCATOR), $row['locator_hash']);
    }

    /** payloadHash und sizeBytes werden abgeleitet, nie von außen gesetzt. */
    public function testHashAndSizeAreDerivedFromTheBlobs(): void
    {
        $payload = base64_encode('irgendwelche Bytes');
        $state = new SyncState(SyncContract::locatorHash(self::LOCATOR), self::STATE_ID, 'bWV0YQ==', $payload);

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
        $a = new SyncState(SyncContract::locatorHash('A' . str_repeat('a', 42)), self::STATE_ID, 'bQ==', 'cA==');
        $b = new SyncState(SyncContract::locatorHash('B' . str_repeat('b', 42)), self::STATE_ID, 'bQ==', 'cA==');

        $this->em->persist($a);
        $this->em->persist($b);
        $this->em->flush();

        self::assertNotSame($a->id, $b->id);
    }

    /** Die Aufbewahrung räumt nach lastAccessAt, nicht nach updatedAt. */
    public function testDeleteUnusedBeforeUsesTheAccessTimestamp(): void
    {
        $alt = new SyncState(SyncContract::locatorHash(self::LOCATOR), self::STATE_ID, 'bQ==', 'cA==');
        $alt->lastAccessAt = new \DateTimeImmutable('-400 days');
        $jung = new SyncState(SyncContract::locatorHash(self::LOCATOR), 'abcdef0123456789abcdef0123456789', 'bQ==', 'cA==');

        $this->em->persist($alt);
        $this->em->persist($jung);
        $this->em->flush();

        $repo = static::getContainer()->get(SyncStateRepository::class);
        $deleted = $repo->deleteUnusedBefore(new \DateTimeImmutable('-365 days'));

        self::assertSame(1, $deleted);
        self::assertSame(1, $repo->countByLocator(SyncContract::locatorHash(self::LOCATOR)));
    }
}
