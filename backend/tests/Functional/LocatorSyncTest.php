<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Api\SyncContract;
use App\Entity\SyncState;
use App\Repository\SyncStateRepository;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Die vier Locator-Sync-Endpunkte des Extension-Vertrags (apiLevel 3).
 *
 * Der wichtigste Test hier ist testPutWithAStaleBaseHashIsRefusedWithoutAnyEffect:
 * der Drei-Wege-Merge der Extension setzt alles auf das 412, und eine
 * Teilwirkung an dieser Stelle überschriebe dem Nutzer stillschweigend die
 * Einstellungen auf dem anderen Rechner.
 */
final class LocatorSyncTest extends ApiTestCase
{
    /**
     * @param array<string, mixed> $body
     *
     * @return array{0: int, 1: array<string, mixed>} Status und dekodierter Body
     */
    private function call(string $method, string $path, array $body): array
    {
        $this->api($method, $path, $body);

        return [$this->client->getResponse()->getStatusCode(), $this->json()];
    }

    /**
     * Ein gueltiger PUT-Umschlag; die Tests ueberschreiben nur ihr Delta,
     * damit sichtbar bleibt, worum es dem einzelnen Fall geht.
     *
     * @param array<string, mixed> $overrides
     *
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function put(array $overrides = []): array
    {
        return $this->call('PUT', '/api/v1/sync/state', $overrides + [
            'apiLevel' => 3,
            'locator' => self::SYNC_LOCATOR,
            'stateId' => self::SYNC_STATE_ID,
            'meta' => 'bWV0YQ==',
            'payload' => 'cGF5bG9hZA==',
        ]);
    }

    // ---------------------------------------------------------------- list

    public function testListReturnsTheStatesOfThatLocatorOnly(): void
    {
        $mine = $this->seedSyncState(self::SYNC_LOCATOR, self::SYNC_STATE_ID);
        $this->seedSyncState(self::SYNC_OTHER_LOCATOR, 'ffffffffffffffffffffffffffffffff');

        [$status, $body] = $this->call('POST', '/api/v1/sync/list', ['apiLevel' => 3, 'locator' => self::SYNC_LOCATOR]);

        self::assertSame(200, $status);
        self::assertCount(1, $body['states']);
        self::assertSame(self::SYNC_STATE_ID, $body['states'][0]['stateId']);
        self::assertSame($mine->sizeBytes, $body['states'][0]['size']);
        self::assertSame('bWV0YQ==', $body['states'][0]['meta']);
        self::assertArrayHasKey('updatedAt', $body['states'][0]);
        // Der payload gehört NICHT in die Liste – genau dafür ist der Stand
        // in zwei Chiffrate geteilt.
        self::assertArrayNotHasKey('payload', $body['states'][0]);
    }

    /** Ein unbekannter Locator ist kein Fehler, sondern eine leere Liste. */
    public function testListOfAnUnknownLocatorIsEmpty(): void
    {
        [$status, $body] = $this->call('POST', '/api/v1/sync/list', ['apiLevel' => 3, 'locator' => self::SYNC_LOCATOR]);

        self::assertSame(200, $status);
        self::assertSame([], $body['states']);
    }

    /**
     * Die Locator-Form wird geprüft, BEVOR sie etwas adressiert. Ohne diese
     * Prüfung wäre der Wert ein Pfad-Traversal.
     */
    #[DataProvider('malformedLocators')]
    public function testAMalformedLocatorIsBadRequest(mixed $locator): void
    {
        [$status, $body] = $this->call('POST', '/api/v1/sync/list', ['apiLevel' => 3, 'locator' => $locator]);

        self::assertSame(400, $status);
        self::assertSame('bad-request', $body['error']);
    }

    /**
     * Nur die Fälle, die SyncContractTest NICHT abdecken kann: dort wird die
     * Regex ohne Kernel gegen Strings geprüft, hier geht es um die Typen, die
     * ein JSON-Body sonst noch liefern kann – plus der Pfad-Traversal als der
     * Fall, dessen Abwehr man auf HTTP-Ebene gesehen haben will.
     */
    public static function malformedLocators(): iterable
    {
        yield 'fehlt' => [null];
        yield 'Zahl' => [12345];
        yield 'Array' => [['a']];
        yield 'Pfad-Traversal' => ['../../../../../../../../../../etc/passwd'];
    }

    public function testAMissingApiLevelIsBadRequest(): void
    {
        [$status, $body] = $this->call('POST', '/api/v1/sync/list', ['locator' => self::SYNC_LOCATOR]);

        self::assertSame(400, $status);
        self::assertSame('bad-request', $body['error']);
    }

    /** Künftige Level dürfen nicht abgewiesen werden (Toleranzregel). */
    public function testAFutureApiLevelIsAccepted(): void
    {
        [$status] = $this->call('POST', '/api/v1/sync/list', ['apiLevel' => 4, 'locator' => self::SYNC_LOCATOR]);

        self::assertSame(200, $status);
    }

    /**
     * Jeder LESEZUGRIFF frischt die Aufbewahrungsfrist auf, ohne updatedAt
     * anzufassen – das ist der Wert, den der Client anzeigt. Gilt für list
     * und get gleichermaßen, deshalb ein Test für beide.
     *
     * @param array<string, mixed> $body
     */
    #[DataProvider('readEndpoints')]
    public function testAReadRefreshesRetentionWithoutTouchingUpdatedAt(string $path, array $body): void
    {
        $state = $this->seedSyncState(self::SYNC_LOCATOR, self::SYNC_STATE_ID);
        $state->updatedAt = new \DateTimeImmutable('-100 days');
        $state->lastAccessAt = new \DateTimeImmutable('-100 days');
        $this->em->flush();
        $updatedAt = $state->updatedAt;

        $this->call('POST', $path, $body + ['apiLevel' => 3, 'locator' => self::SYNC_LOCATOR]);
        $this->em->refresh($state);

        // Sekundengenau vergleichen: die Spalte ist DATETIME und das
        // Wire-Format ATOM – Mikrosekunden gibt es an keiner der beiden
        // Stellen, nur im frisch erzeugten PHP-Objekt.
        self::assertSame($updatedAt->format(\DateTimeInterface::ATOM), $state->updatedAt->format(\DateTimeInterface::ATOM));
        self::assertGreaterThan(new \DateTimeImmutable('-1 hour'), $state->lastAccessAt);
    }

    public static function readEndpoints(): iterable
    {
        yield 'list' => ['/api/v1/sync/list', []];
        yield 'get' => ['/api/v1/sync/get', ['stateId' => self::SYNC_STATE_ID]];
    }

    // ----------------------------------------------------------------- put

    public function testPutCreatesANewState(): void
    {
        [$status, $body] = $this->put();

        self::assertSame(200, $status);
        self::assertSame(self::SYNC_STATE_ID, $body['stateId']);
        self::assertSame(\strlen('cGF5bG9hZA=='), $body['size']);
        self::assertArrayHasKey('updatedAt', $body);
        // Der payloadHash gehört NICHT in die Antwort – ihn kennt nur der
        // hochladende Client, weil jede Verschlüsselung eine frische IV nutzt.
        self::assertArrayNotHasKey('payloadHash', $body);
    }

    /** Ohne basePayloadHash wird bedingungslos geschrieben. */
    public function testPutWithoutABaseHashOverwritesUnconditionally(): void
    {
        $this->seedSyncState(self::SYNC_LOCATOR, self::SYNC_STATE_ID, base64_encode('alt'));

        [$status] = $this->put(['payload' => base64_encode('neu')]);

        self::assertSame(200, $status);
        $this->em->clear();
        $state = $this->syncStates()->findOneByLocatorAndState(SyncContract::locatorHash(self::SYNC_LOCATOR), self::SYNC_STATE_ID);
        self::assertSame(base64_encode('neu'), $state->payload);
    }

    /** Passender basePayloadHash: es wird geschrieben. */
    public function testPutWithAMatchingBaseHashWrites(): void
    {
        $state = $this->seedSyncState(self::SYNC_LOCATOR, self::SYNC_STATE_ID, base64_encode('alt'));

        [$status] = $this->put(['payload' => base64_encode('neu'), 'basePayloadHash' => $state->payloadHash]);

        self::assertSame(200, $status);
    }

    /**
     * Der tragende Fall: falscher basePayloadHash → 412, updatedAt im Body,
     * und NICHTS wurde geschrieben. Scheitert dieser Test mit
     * »EntityManagerClosed«, wirft ein Guard noch aus der Transaktions-Closure
     * heraus (siehe LocatorSyncService).
     */
    public function testPutWithAStaleBaseHashIsRefusedWithoutAnyEffect(): void
    {
        $state = $this->seedSyncState(self::SYNC_LOCATOR, self::SYNC_STATE_ID, base64_encode('alt'));
        $vorher = ['payload' => $state->payload, 'meta' => $state->meta, 'updatedAt' => $state->updatedAt];

        [$status, $body] = $this->put([
            'meta' => 'bmV1',
            'payload' => base64_encode('neu'),
            'basePayloadHash' => SyncContract::payloadHash(base64_encode('etwas ganz anderes')),
        ]);

        self::assertSame(412, $status);
        self::assertSame('conflict', $body['error']);
        self::assertSame($vorher['updatedAt']->format(\DateTimeInterface::ATOM), $body['updatedAt']);

        // Nach dem 412 muss der EntityManager noch benutzbar und der Stand
        // unverändert sein – kein Byte darf geschrieben worden sein.
        $this->em->clear();
        $nachher = $this->syncStates()->findOneByLocatorAndState(SyncContract::locatorHash(self::SYNC_LOCATOR), self::SYNC_STATE_ID);
        self::assertSame($vorher['payload'], $nachher->payload);
        self::assertSame($vorher['meta'], $nachher->meta);
        self::assertSame($vorher['updatedAt']->format(\DateTimeInterface::ATOM), $nachher->updatedAt->format(\DateTimeInterface::ATOM));
    }

    /**
     * Nach einem 412 versucht der Client es ohne Feld erneut (»trotzdem
     * überschreiben«) – das muss durchgehen.
     */
    public function testAfterAConflictAnUnconditionalRetryWrites(): void
    {
        $this->seedSyncState(self::SYNC_LOCATOR, self::SYNC_STATE_ID, base64_encode('alt'));

        $this->put([
            'payload' => base64_encode('neu'),
            'basePayloadHash' => SyncContract::payloadHash(base64_encode('falsch')),
        ]);
        [$status] = $this->put(['payload' => base64_encode('neu')]);

        self::assertSame(200, $status);
        $this->em->clear();
        $state = $this->syncStates()->findOneByLocatorAndState(SyncContract::locatorHash(self::SYNC_LOCATOR), self::SYNC_STATE_ID);
        self::assertSame(base64_encode('neu'), $state->payload);
    }

    /**
     * Ein basePayloadHash auf einen Stand, den es nicht gibt: der Client hat
     * eine Basis, die der Server nicht kennt. Stillschweigend anzulegen hieße,
     * eine fremde Löschung zu übergehen.
     */
    public function testPutWithABaseHashOnAMissingStateIsNotFound(): void
    {
        [$status, $body] = $this->put(['basePayloadHash' => SyncContract::payloadHash(base64_encode('alt'))]);

        self::assertSame(404, $status);
        self::assertSame('not-found', $body['error']);
    }

    /** Ein basePayloadHash in falscher Form ist bad-request. */
    public function testAMalformedBaseHashIsBadRequest(): void
    {
        [$status, $body] = $this->put(['basePayloadHash' => 'zu-kurz']);

        self::assertSame(400, $status);
        self::assertSame('bad-request', $body['error']);
    }

    /** Die Ständegrenze wird nur beim ANLEGEN geprüft. */
    public function testTheSixthNewStateIsRefusedWithQuotaStates(): void
    {
        for ($i = 0; $i < SyncContract::MAX_STATES_PER_LOCATOR; ++$i) {
            $this->seedSyncState(self::SYNC_LOCATOR, str_pad((string) $i, 32, '0', STR_PAD_LEFT));
        }

        [$status, $body] = $this->put(['stateId' => 'ffffffffffffffffffffffffffffffff']);

        self::assertSame(409, $status);
        self::assertSame('quota-states', $body['error']);
    }

    /** Über der Grenze bleibt SCHREIBEN auf bestehende Stände möglich. */
    public function testOverTheQuotaExistingStatesStayWritable(): void
    {
        for ($i = 0; $i < SyncContract::MAX_STATES_PER_LOCATOR + 2; ++$i) {
            $this->seedSyncState(self::SYNC_LOCATOR, str_pad((string) $i, 32, '0', STR_PAD_LEFT));
        }

        [$status] = $this->put(['stateId' => str_pad('0', 32, '0', STR_PAD_LEFT), 'payload' => base64_encode('neu')]);

        self::assertSame(200, $status);
    }

    #[DataProvider('oversizedBlobs')]
    public function testAnOversizedBlobIsTooLarge(string $key, int $length): void
    {
        [$status, $answer] = $this->put([$key => str_repeat('A', $length)]);

        self::assertSame(413, $status);
        self::assertSame('too-large', $answer['error']);
    }

    public static function oversizedBlobs(): iterable
    {
        yield 'meta über 8 KiB' => ['meta', SyncContract::MAX_META_BYTES + 1];
        yield 'payload über 512 KiB' => ['payload', SyncContract::MAX_PAYLOAD_BYTES + 1];
    }

    /** Genau auf der Grenze wird noch angenommen. */
    public function testABlobExactlyAtTheLimitIsAccepted(): void
    {
        [$status, $body] = $this->put(['payload' => str_repeat('A', SyncContract::MAX_PAYLOAD_BYTES)]);

        self::assertSame(200, $status);
        self::assertSame(SyncContract::MAX_PAYLOAD_BYTES, $body['size']);
    }

    /** Kein sauberes Base64 ist bad-request, nicht »irgendwelche Bytes«. */
    public function testANonBase64EnvelopeIsBadRequest(): void
    {
        [$status, $body] = $this->put(['payload' => 'kein base64 !!!']);

        self::assertSame(400, $status);
        self::assertSame('bad-request', $body['error']);
    }

    /** Ein Stand gehört seinem Locator – fremde stateIds sind unsichtbar. */
    public function testPutUnderAnotherLocatorDoesNotTouchTheForeignState(): void
    {
        $fremd = $this->seedSyncState(self::SYNC_OTHER_LOCATOR, self::SYNC_STATE_ID, base64_encode('fremd'));

        $this->put(['payload' => base64_encode('meins')]);

        $this->em->refresh($fremd);
        self::assertSame(base64_encode('fremd'), $fremd->payload);
    }

    // ----------------------------------------------------------------- get

    public function testGetReturnsThePayload(): void
    {
        $state = $this->seedSyncState(self::SYNC_LOCATOR, self::SYNC_STATE_ID, base64_encode('geheim'));

        [$status, $body] = $this->call('POST', '/api/v1/sync/get', [
            'apiLevel' => 3, 'locator' => self::SYNC_LOCATOR, 'stateId' => self::SYNC_STATE_ID,
        ]);

        self::assertSame(200, $status);
        self::assertSame(self::SYNC_STATE_ID, $body['stateId']);
        self::assertSame(base64_encode('geheim'), $body['payload']);
        self::assertSame($state->updatedAt->format(\DateTimeInterface::ATOM), $body['updatedAt']);
    }

    /** Ein fremder Stand ist unter meinem Locator schlicht nicht da. */
    public function testGetOfAForeignStateIsNotFound(): void
    {
        $this->seedSyncState(self::SYNC_OTHER_LOCATOR, self::SYNC_STATE_ID);

        [$status, $body] = $this->call('POST', '/api/v1/sync/get', [
            'apiLevel' => 3, 'locator' => self::SYNC_LOCATOR, 'stateId' => self::SYNC_STATE_ID,
        ]);

        self::assertSame(404, $status);
        self::assertSame('not-found', $body['error']);
    }

    /** get ohne stateId ist bad-request – hier ist er Pflicht. */
    public function testGetWithoutAStateIdIsBadRequest(): void
    {
        [$status, $body] = $this->call('POST', '/api/v1/sync/get', ['apiLevel' => 3, 'locator' => self::SYNC_LOCATOR]);

        self::assertSame(400, $status);
        self::assertSame('bad-request', $body['error']);
    }

    // -------------------------------------------------------------- delete

    public function testDeleteRemovesOneState(): void
    {
        $this->seedSyncState(self::SYNC_LOCATOR, self::SYNC_STATE_ID);
        $this->seedSyncState(self::SYNC_LOCATOR, 'ffffffffffffffffffffffffffffffff');

        [$status, $body] = $this->call('POST', '/api/v1/sync/delete', [
            'apiLevel' => 3, 'locator' => self::SYNC_LOCATOR, 'stateId' => self::SYNC_STATE_ID,
        ]);

        self::assertSame(200, $status);
        self::assertSame(1, $body['deleted']);
        self::assertSame(1, $this->syncStates()->countByLocator(SyncContract::locatorHash(self::SYNC_LOCATOR)));
    }

    /** Ohne stateId fällt alles unter dem Locator – so steht es im Vertrag. */
    public function testDeleteWithoutAStateIdRemovesEverythingUnderTheLocator(): void
    {
        $this->seedSyncState(self::SYNC_LOCATOR, self::SYNC_STATE_ID);
        $this->seedSyncState(self::SYNC_LOCATOR, 'ffffffffffffffffffffffffffffffff');
        $this->seedSyncState(self::SYNC_OTHER_LOCATOR, self::SYNC_STATE_ID);

        [$status, $body] = $this->call('POST', '/api/v1/sync/delete', ['apiLevel' => 3, 'locator' => self::SYNC_LOCATOR]);

        self::assertSame(200, $status);
        self::assertSame(2, $body['deleted']);
        self::assertSame(0, $this->syncStates()->countByLocator(SyncContract::locatorHash(self::SYNC_LOCATOR)));
        self::assertSame(1, $this->syncStates()->countByLocator(SyncContract::locatorHash(self::SYNC_OTHER_LOCATOR)));
    }

    public function testDeleteOfAnUnknownStateIsNotFound(): void
    {
        [$status, $body] = $this->call('POST', '/api/v1/sync/delete', [
            'apiLevel' => 3, 'locator' => self::SYNC_LOCATOR, 'stateId' => self::SYNC_STATE_ID,
        ]);

        self::assertSame(404, $status);
        self::assertSame('not-found', $body['error']);
    }

    /** Ein leerer Locator ohne stateId löscht nichts und meldet 0. */
    public function testDeleteEverythingUnderAnEmptyLocatorReportsZero(): void
    {
        [$status, $body] = $this->call('POST', '/api/v1/sync/delete', ['apiLevel' => 3, 'locator' => self::SYNC_LOCATOR]);

        self::assertSame(200, $status);
        self::assertSame(0, $body['deleted']);
    }
}
