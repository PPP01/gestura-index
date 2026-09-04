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
    private const LOCATOR = 'zoogXw2lwmt_ZqFnRu-lFOWYxyJaU2kpxfunpy3Umsk';
    private const OTHER_LOCATOR = '3qzyS44KqXaBNzKvFSontDE8CfLPp8lwOUVHroaeg7M';
    private const STATE_ID = '0123456789abcdef0123456789abcdef';

    /**
     * @param array<string, mixed> $body
     *
     * @return array{0: int, 1: mixed}
     */
    private function call(string $method, string $path, array $body): array
    {
        $this->client->request($method, $path, server: ['CONTENT_TYPE' => 'application/json'], content: json_encode($body, JSON_THROW_ON_ERROR));
        $response = $this->client->getResponse();

        return [$response->getStatusCode(), json_decode((string) $response->getContent(), true)];
    }

    private function seedState(string $locator, string $stateId, string $payload = 'cGF5bG9hZA=='): SyncState
    {
        $state = new SyncState(SyncContract::locatorHash($locator), $stateId, 'bWV0YQ==', $payload);
        $this->em->persist($state);
        $this->em->flush();

        return $state;
    }

    private function repo(): SyncStateRepository
    {
        return static::getContainer()->get(SyncStateRepository::class);
    }

    // ---------------------------------------------------------------- list

    public function testListReturnsTheStatesOfThatLocatorOnly(): void
    {
        $mine = $this->seedState(self::LOCATOR, self::STATE_ID);
        $this->seedState(self::OTHER_LOCATOR, 'ffffffffffffffffffffffffffffffff');

        [$status, $body] = $this->call('POST', '/api/v1/sync/list', ['apiLevel' => 3, 'locator' => self::LOCATOR]);

        self::assertSame(200, $status);
        self::assertCount(1, $body['states']);
        self::assertSame(self::STATE_ID, $body['states'][0]['stateId']);
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
        [$status, $body] = $this->call('POST', '/api/v1/sync/list', ['apiLevel' => 3, 'locator' => self::LOCATOR]);

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

    public static function malformedLocators(): iterable
    {
        yield 'fehlt' => [null];
        yield 'zu kurz' => [str_repeat('a', 42)];
        yield 'Pfad-Traversal' => ['../../../../../../../../../../etc/passwd'];
        yield 'Standard-Base64' => [str_repeat('a', 41) . '+/='];
        yield 'Zahl' => [12345];
        yield 'Array' => [['a']];
    }

    public function testAMissingApiLevelIsBadRequest(): void
    {
        [$status, $body] = $this->call('POST', '/api/v1/sync/list', ['locator' => self::LOCATOR]);

        self::assertSame(400, $status);
        self::assertSame('bad-request', $body['error']);
    }

    /** Künftige Level dürfen nicht abgewiesen werden (Toleranzregel). */
    public function testAFutureApiLevelIsAccepted(): void
    {
        [$status] = $this->call('POST', '/api/v1/sync/list', ['apiLevel' => 4, 'locator' => self::LOCATOR]);

        self::assertSame(200, $status);
    }

    /** Listen frischt die Aufbewahrungsfrist auf, ohne updatedAt zu rühren. */
    public function testListRefreshesRetentionWithoutTouchingUpdatedAt(): void
    {
        $state = $this->seedState(self::LOCATOR, self::STATE_ID);
        $state->updatedAt = new \DateTimeImmutable('-100 days');
        $state->lastAccessAt = new \DateTimeImmutable('-100 days');
        $this->em->flush();
        $updatedAt = $state->updatedAt;

        $this->call('POST', '/api/v1/sync/list', ['apiLevel' => 3, 'locator' => self::LOCATOR]);
        $this->em->refresh($state);

        // Sekundengenau vergleichen: die Spalte ist DATETIME und das
        // Wire-Format ATOM - Mikrosekunden gibt es an keiner der beiden
        // Stellen, nur im frisch erzeugten PHP-Objekt.
        self::assertSame($updatedAt->format(\DateTimeInterface::ATOM), $state->updatedAt->format(\DateTimeInterface::ATOM));
        self::assertGreaterThan(new \DateTimeImmutable('-1 hour'), $state->lastAccessAt);
    }

    /** Der Preflight muss beantwortet werden – Firefox schickt einen. */
    public function testThePreflightIsAnswered(): void
    {
        $this->client->request('OPTIONS', '/api/v1/sync/state');
        $response = $this->client->getResponse();

        self::assertSame(204, $response->getStatusCode());
        self::assertSame('*', $response->headers->get('Access-Control-Allow-Origin'));
        self::assertStringContainsString('PUT', (string) $response->headers->get('Access-Control-Allow-Methods'));
        self::assertStringContainsString('POST', (string) $response->headers->get('Access-Control-Allow-Methods'));
        self::assertStringContainsString('Content-Type', (string) $response->headers->get('Access-Control-Allow-Headers'));
    }

    // ----------------------------------------------------------------- put

    public function testPutCreatesANewState(): void
    {
        [$status, $body] = $this->call('PUT', '/api/v1/sync/state', [
            'apiLevel' => 3, 'locator' => self::LOCATOR, 'stateId' => self::STATE_ID,
            'meta' => 'bWV0YQ==', 'payload' => 'cGF5bG9hZA==',
        ]);

        self::assertSame(200, $status);
        self::assertSame(self::STATE_ID, $body['stateId']);
        self::assertSame(\strlen('cGF5bG9hZA=='), $body['size']);
        self::assertArrayHasKey('updatedAt', $body);
        // Der payloadHash gehört NICHT in die Antwort – ihn kennt nur der
        // hochladende Client, weil jede Verschlüsselung eine frische IV nutzt.
        self::assertArrayNotHasKey('payloadHash', $body);
    }

    /** Ohne basePayloadHash wird bedingungslos geschrieben. */
    public function testPutWithoutABaseHashOverwritesUnconditionally(): void
    {
        $this->seedState(self::LOCATOR, self::STATE_ID, base64_encode('alt'));

        [$status] = $this->call('PUT', '/api/v1/sync/state', [
            'apiLevel' => 3, 'locator' => self::LOCATOR, 'stateId' => self::STATE_ID,
            'meta' => 'bmV1', 'payload' => base64_encode('neu'),
        ]);

        self::assertSame(200, $status);
        $this->em->clear();
        $state = $this->repo()->findOneByLocatorAndState(SyncContract::locatorHash(self::LOCATOR), self::STATE_ID);
        self::assertSame(base64_encode('neu'), $state->payload);
    }

    /** Passender basePayloadHash: es wird geschrieben. */
    public function testPutWithAMatchingBaseHashWrites(): void
    {
        $state = $this->seedState(self::LOCATOR, self::STATE_ID, base64_encode('alt'));

        [$status] = $this->call('PUT', '/api/v1/sync/state', [
            'apiLevel' => 3, 'locator' => self::LOCATOR, 'stateId' => self::STATE_ID,
            'meta' => 'bmV1', 'payload' => base64_encode('neu'),
            'basePayloadHash' => $state->payloadHash,
        ]);

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
        $state = $this->seedState(self::LOCATOR, self::STATE_ID, base64_encode('alt'));
        $vorher = ['payload' => $state->payload, 'meta' => $state->meta, 'updatedAt' => $state->updatedAt];

        [$status, $body] = $this->call('PUT', '/api/v1/sync/state', [
            'apiLevel' => 3, 'locator' => self::LOCATOR, 'stateId' => self::STATE_ID,
            'meta' => 'bmV1', 'payload' => base64_encode('neu'),
            'basePayloadHash' => SyncContract::payloadHash(base64_encode('etwas ganz anderes')),
        ]);

        self::assertSame(412, $status);
        self::assertSame('conflict', $body['error']);
        self::assertSame($vorher['updatedAt']->format(\DateTimeInterface::ATOM), $body['updatedAt']);

        // Nach dem 412 muss der EntityManager noch benutzbar und der Stand
        // unverändert sein – kein Byte darf geschrieben worden sein.
        $this->em->clear();
        $nachher = $this->repo()->findOneByLocatorAndState(SyncContract::locatorHash(self::LOCATOR), self::STATE_ID);
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
        $this->seedState(self::LOCATOR, self::STATE_ID, base64_encode('alt'));

        $this->call('PUT', '/api/v1/sync/state', [
            'apiLevel' => 3, 'locator' => self::LOCATOR, 'stateId' => self::STATE_ID,
            'meta' => 'bmV1', 'payload' => base64_encode('neu'),
            'basePayloadHash' => SyncContract::payloadHash(base64_encode('falsch')),
        ]);
        [$status] = $this->call('PUT', '/api/v1/sync/state', [
            'apiLevel' => 3, 'locator' => self::LOCATOR, 'stateId' => self::STATE_ID,
            'meta' => 'bmV1', 'payload' => base64_encode('neu'),
        ]);

        self::assertSame(200, $status);
        $this->em->clear();
        $state = $this->repo()->findOneByLocatorAndState(SyncContract::locatorHash(self::LOCATOR), self::STATE_ID);
        self::assertSame(base64_encode('neu'), $state->payload);
    }

    /**
     * Ein basePayloadHash auf einen Stand, den es nicht gibt: der Client hat
     * eine Basis, die der Server nicht kennt. Stillschweigend anzulegen hieße,
     * eine fremde Löschung zu übergehen.
     */
    public function testPutWithABaseHashOnAMissingStateIsNotFound(): void
    {
        [$status, $body] = $this->call('PUT', '/api/v1/sync/state', [
            'apiLevel' => 3, 'locator' => self::LOCATOR, 'stateId' => self::STATE_ID,
            'meta' => 'bmV1', 'payload' => base64_encode('neu'),
            'basePayloadHash' => SyncContract::payloadHash(base64_encode('alt')),
        ]);

        self::assertSame(404, $status);
        self::assertSame('not-found', $body['error']);
    }

    /** Ein basePayloadHash in falscher Form ist bad-request. */
    public function testAMalformedBaseHashIsBadRequest(): void
    {
        [$status, $body] = $this->call('PUT', '/api/v1/sync/state', [
            'apiLevel' => 3, 'locator' => self::LOCATOR, 'stateId' => self::STATE_ID,
            'meta' => 'bmV1', 'payload' => base64_encode('neu'), 'basePayloadHash' => 'zu-kurz',
        ]);

        self::assertSame(400, $status);
        self::assertSame('bad-request', $body['error']);
    }

    /** Die Ständegrenze wird nur beim ANLEGEN geprüft. */
    public function testTheSixthNewStateIsRefusedWithQuotaStates(): void
    {
        for ($i = 0; $i < SyncContract::MAX_STATES_PER_LOCATOR; ++$i) {
            $this->seedState(self::LOCATOR, str_pad((string) $i, 32, '0', STR_PAD_LEFT));
        }

        [$status, $body] = $this->call('PUT', '/api/v1/sync/state', [
            'apiLevel' => 3, 'locator' => self::LOCATOR, 'stateId' => 'ffffffffffffffffffffffffffffffff',
            'meta' => 'bQ==', 'payload' => 'cA==',
        ]);

        self::assertSame(409, $status);
        self::assertSame('quota-states', $body['error']);
    }

    /** Über der Grenze bleibt SCHREIBEN auf bestehende Stände möglich. */
    public function testOverTheQuotaExistingStatesStayWritable(): void
    {
        for ($i = 0; $i < SyncContract::MAX_STATES_PER_LOCATOR + 2; ++$i) {
            $this->seedState(self::LOCATOR, str_pad((string) $i, 32, '0', STR_PAD_LEFT));
        }

        [$status] = $this->call('PUT', '/api/v1/sync/state', [
            'apiLevel' => 3, 'locator' => self::LOCATOR, 'stateId' => str_pad('0', 32, '0', STR_PAD_LEFT),
            'meta' => 'bmV1', 'payload' => base64_encode('neu'),
        ]);

        self::assertSame(200, $status);
    }

    #[DataProvider('oversizedBlobs')]
    public function testAnOversizedBlobIsTooLarge(string $key, int $length): void
    {
        $body = [
            'apiLevel' => 3, 'locator' => self::LOCATOR, 'stateId' => self::STATE_ID,
            'meta' => 'bQ==', 'payload' => 'cA==',
        ];
        $body[$key] = str_repeat('A', $length);

        [$status, $answer] = $this->call('PUT', '/api/v1/sync/state', $body);

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
        [$status, $body] = $this->call('PUT', '/api/v1/sync/state', [
            'apiLevel' => 3, 'locator' => self::LOCATOR, 'stateId' => self::STATE_ID,
            'meta' => 'bQ==', 'payload' => str_repeat('A', SyncContract::MAX_PAYLOAD_BYTES),
        ]);

        self::assertSame(200, $status);
        self::assertSame(SyncContract::MAX_PAYLOAD_BYTES, $body['size']);
    }

    /** Kein sauberes Base64 ist bad-request, nicht »irgendwelche Bytes«. */
    public function testANonBase64EnvelopeIsBadRequest(): void
    {
        [$status, $body] = $this->call('PUT', '/api/v1/sync/state', [
            'apiLevel' => 3, 'locator' => self::LOCATOR, 'stateId' => self::STATE_ID,
            'meta' => 'bQ==', 'payload' => 'kein base64 !!!',
        ]);

        self::assertSame(400, $status);
        self::assertSame('bad-request', $body['error']);
    }

    /** Ein Stand gehört seinem Locator – fremde stateIds sind unsichtbar. */
    public function testPutUnderAnotherLocatorDoesNotTouchTheForeignState(): void
    {
        $fremd = $this->seedState(self::OTHER_LOCATOR, self::STATE_ID, base64_encode('fremd'));

        $this->call('PUT', '/api/v1/sync/state', [
            'apiLevel' => 3, 'locator' => self::LOCATOR, 'stateId' => self::STATE_ID,
            'meta' => 'bmV1', 'payload' => base64_encode('meins'),
        ]);

        $this->em->refresh($fremd);
        self::assertSame(base64_encode('fremd'), $fremd->payload);
    }

    // ----------------------------------------------------------------- get

    public function testGetReturnsThePayload(): void
    {
        $state = $this->seedState(self::LOCATOR, self::STATE_ID, base64_encode('geheim'));

        [$status, $body] = $this->call('POST', '/api/v1/sync/get', [
            'apiLevel' => 3, 'locator' => self::LOCATOR, 'stateId' => self::STATE_ID,
        ]);

        self::assertSame(200, $status);
        self::assertSame(self::STATE_ID, $body['stateId']);
        self::assertSame(base64_encode('geheim'), $body['payload']);
        self::assertSame($state->updatedAt->format(\DateTimeInterface::ATOM), $body['updatedAt']);
    }

    /** Ein fremder Stand ist unter meinem Locator schlicht nicht da. */
    public function testGetOfAForeignStateIsNotFound(): void
    {
        $this->seedState(self::OTHER_LOCATOR, self::STATE_ID);

        [$status, $body] = $this->call('POST', '/api/v1/sync/get', [
            'apiLevel' => 3, 'locator' => self::LOCATOR, 'stateId' => self::STATE_ID,
        ]);

        self::assertSame(404, $status);
        self::assertSame('not-found', $body['error']);
    }

    /** get ohne stateId ist bad-request – hier ist er Pflicht. */
    public function testGetWithoutAStateIdIsBadRequest(): void
    {
        [$status, $body] = $this->call('POST', '/api/v1/sync/get', ['apiLevel' => 3, 'locator' => self::LOCATOR]);

        self::assertSame(400, $status);
        self::assertSame('bad-request', $body['error']);
    }

    /** Herunterladen frischt die Aufbewahrungsfrist auf, nicht updatedAt. */
    public function testGetRefreshesRetentionWithoutTouchingUpdatedAt(): void
    {
        $state = $this->seedState(self::LOCATOR, self::STATE_ID);
        $state->updatedAt = new \DateTimeImmutable('-100 days');
        $state->lastAccessAt = new \DateTimeImmutable('-100 days');
        $this->em->flush();
        $updatedAt = $state->updatedAt;

        $this->call('POST', '/api/v1/sync/get', ['apiLevel' => 3, 'locator' => self::LOCATOR, 'stateId' => self::STATE_ID]);
        $this->em->refresh($state);

        // Sekundengenau vergleichen: die Spalte ist DATETIME und das
        // Wire-Format ATOM - Mikrosekunden gibt es an keiner der beiden
        // Stellen, nur im frisch erzeugten PHP-Objekt.
        self::assertSame($updatedAt->format(\DateTimeInterface::ATOM), $state->updatedAt->format(\DateTimeInterface::ATOM));
        self::assertGreaterThan(new \DateTimeImmutable('-1 hour'), $state->lastAccessAt);
    }

    // -------------------------------------------------------------- delete

    public function testDeleteRemovesOneState(): void
    {
        $this->seedState(self::LOCATOR, self::STATE_ID);
        $this->seedState(self::LOCATOR, 'ffffffffffffffffffffffffffffffff');

        [$status, $body] = $this->call('POST', '/api/v1/sync/delete', [
            'apiLevel' => 3, 'locator' => self::LOCATOR, 'stateId' => self::STATE_ID,
        ]);

        self::assertSame(200, $status);
        self::assertSame(1, $body['deleted']);
        self::assertSame(1, $this->repo()->countByLocator(SyncContract::locatorHash(self::LOCATOR)));
    }

    /** Ohne stateId fällt alles unter dem Locator – so steht es im Vertrag. */
    public function testDeleteWithoutAStateIdRemovesEverythingUnderTheLocator(): void
    {
        $this->seedState(self::LOCATOR, self::STATE_ID);
        $this->seedState(self::LOCATOR, 'ffffffffffffffffffffffffffffffff');
        $this->seedState(self::OTHER_LOCATOR, self::STATE_ID);

        [$status, $body] = $this->call('POST', '/api/v1/sync/delete', ['apiLevel' => 3, 'locator' => self::LOCATOR]);

        self::assertSame(200, $status);
        self::assertSame(2, $body['deleted']);
        self::assertSame(0, $this->repo()->countByLocator(SyncContract::locatorHash(self::LOCATOR)));
        self::assertSame(1, $this->repo()->countByLocator(SyncContract::locatorHash(self::OTHER_LOCATOR)));
    }

    public function testDeleteOfAnUnknownStateIsNotFound(): void
    {
        [$status, $body] = $this->call('POST', '/api/v1/sync/delete', [
            'apiLevel' => 3, 'locator' => self::LOCATOR, 'stateId' => self::STATE_ID,
        ]);

        self::assertSame(404, $status);
        self::assertSame('not-found', $body['error']);
    }

    /** Ein leerer Locator ohne stateId löscht nichts und meldet 0. */
    public function testDeleteEverythingUnderAnEmptyLocatorReportsZero(): void
    {
        [$status, $body] = $this->call('POST', '/api/v1/sync/delete', ['apiLevel' => 3, 'locator' => self::LOCATOR]);

        self::assertSame(200, $status);
        self::assertSame(0, $body['deleted']);
    }
}
