<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Api\SyncContract;
use App\Entity\SyncState;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * POST /api/v1/sync/meta – Meta-Blob ersetzen, Payload unangetastet.
 *
 * Der wichtigste Test ist testAConflictOnMetaIsRefusedWithoutAnyEffect: das
 * 412 muss als Sentinel aus der Closure zurückgegeben werden, nicht geworfen –
 * sonst schließt wrapInTransaction den EntityManager, und der Client, der nach
 * einem Konflikt neu merged und bis zu dreimal wiederholt, bekäme einen 500er.
 * Die Absicherung ist der explizite `assertTrue($this->em->isOpen())`-Assert
 * direkt nach dem 412 (siehe .claude/lessons.md, »wrapInTransaction-Falle«).
 *
 * Der Retention-Test sichert ab, dass das Umbenennen die 12-Monats-Frist neu
 * startet – obwohl updatedAt stehen bleibt, muss lastAccessAt fortgeschrieben
 * werden, sonst ginge ein umbenannter Stand noch im selben Jahr verloren.
 */
final class LocatorSyncMetaTest extends ApiTestCase
{
    /**
     * @param array<string, mixed> $body
     *
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function call(string $method, string $path, array $body): array
    {
        $this->api($method, $path, $body);

        return [$this->client->getResponse()->getStatusCode(), $this->json()];
    }

    /**
     * Ruft POST /api/v1/sync/meta auf; $overrides ergänzen oder ersetzen die
     * Defaults. basePayloadHash fehlt im Default absichtlich.
     *
     * @param array<string, mixed> $overrides
     *
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function metaCall(array $overrides = []): array
    {
        return $this->call('POST', '/api/v1/sync/meta', $overrides + [
            'apiLevel' => 3,
            'locator' => self::SYNC_LOCATOR,
            'stateId' => self::SYNC_STATE_ID,
            'meta' => 'bmV1TWV0YQ==',
        ]);
    }

    // ------------------------------------------------ Erfolgsfall (200)

    /**
     * Kern des Endpunkts: meta wird ersetzt, payload bleibt unverändert,
     * updatedAt und size sind dieselben wie vor dem Umbenennen.
     *
     * createdAt und updatedAt werden auf deutlich alte, UNTERSCHIEDLICHE
     * Fixpunkte gesetzt (–200 / –100 Tage), damit ein fälschliches
     * Fortschreiben innerhalb derselben Sekunde sichtbar wird.
     * Sekundengenauer Vergleich über format(ATOM): die Spalte ist DATETIME,
     * das Wire-Format ATOM – Mikrosekunden gibt es nur im frisch erzeugten
     * PHP-Objekt (siehe .claude/lessons.md).
     */
    public function testMetaIsReplacedWhilePayloadAndUpdatedAtAreUntouched(): void
    {
        $state = $this->seedSyncState(self::SYNC_LOCATOR, self::SYNC_STATE_ID, base64_encode('inhalt'));
        $state->createdAt = new \DateTimeImmutable('-200 days');
        $state->updatedAt = new \DateTimeImmutable('-100 days');
        $this->em->flush();
        $fixCreatedAt = SyncContract::formatTimestamp($state->createdAt);
        $fixUpdatedAt = SyncContract::formatTimestamp($state->updatedAt);
        $vorher = [
            'payload' => $state->payload,
            'size' => $state->sizeBytes,
        ];

        [$status, $body] = $this->metaCall(['basePayloadHash' => $state->payloadHash, 'meta' => 'bmV1']);

        self::assertSame(200, $status);
        self::assertSame(self::SYNC_STATE_ID, $body['stateId']);
        self::assertSame($fixUpdatedAt, $body['updatedAt']);
        self::assertSame($vorher['size'], $body['size']);

        // Direkt in der DB prüfen – nicht nur in der Antwort
        $this->em->clear();
        $nachher = $this->syncStates()->findOneByLocatorAndState(
            SyncContract::locatorHash(self::SYNC_LOCATOR),
            self::SYNC_STATE_ID,
        );
        self::assertSame('bmV1', $nachher->meta);
        self::assertSame($vorher['payload'], $nachher->payload);
        self::assertSame($fixUpdatedAt, SyncContract::formatTimestamp($nachher->updatedAt));
        self::assertSame($fixCreatedAt, SyncContract::formatTimestamp($nachher->createdAt));
        self::assertSame($vorher['size'], $nachher->sizeBytes);
    }

    /**
     * /sync/list liefert nach dem Umbenennen das NEUE meta, aber denselben
     * payload-freien Datensatz – der Payload-Endpunkt get liefert nach wie vor
     * denselben Inhalt.
     */
    public function testListAndGetReflectTheNewMetaWithUnchangedPayload(): void
    {
        $state = $this->seedSyncState(self::SYNC_LOCATOR, self::SYNC_STATE_ID, base64_encode('inhalt'));

        $this->metaCall(['basePayloadHash' => $state->payloadHash, 'meta' => 'bmV1']);

        [$listStatus, $listBody] = $this->call('POST', '/api/v1/sync/list', [
            'apiLevel' => 3, 'locator' => self::SYNC_LOCATOR,
        ]);
        self::assertSame(200, $listStatus);
        self::assertCount(1, $listBody['states']);
        self::assertSame('bmV1', $listBody['states'][0]['meta']);

        [$getStatus, $getBody] = $this->call('POST', '/api/v1/sync/get', [
            'apiLevel' => 3, 'locator' => self::SYNC_LOCATOR, 'stateId' => self::SYNC_STATE_ID,
        ]);
        self::assertSame(200, $getStatus);
        self::assertSame(base64_encode('inhalt'), $getBody['payload']);
    }

    // ------------------------------------------------ Fehlercode 412

    /**
     * Der tragende Fall: falscher basePayloadHash → 412, updatedAt im Body,
     * NICHTS wurde geschrieben. Das Sentinel-Muster (SyncProblem zurückgeben
     * statt werfen) wird durch `assertTrue($this->em->isOpen())` direkt nach
     * dem 412 abgesichert – dieser Assert schlägt fehl, sobald replaceMeta()
     * das SyncProblem aus der wrapInTransaction-Closure wirft statt zurückgibt.
     *
     * Alle Felder werden VOR dem 412 auf feste Zeitpunkte gesetzt und danach
     * VOLLSTÄNDIG und VOR dem list-Request gegen die DB geprüft – ein
     * Teilupdate oder ein versehentlicher Retention-Touch wäre so sichtbar.
     */
    public function testAConflictOnMetaIsRefusedWithoutAnyEffect(): void
    {
        $state = $this->seedSyncState(self::SYNC_LOCATOR, self::SYNC_STATE_ID);
        $state->createdAt = new \DateTimeImmutable('-200 days');
        $state->updatedAt = new \DateTimeImmutable('-100 days');
        $state->lastAccessAt = new \DateTimeImmutable('-50 days');
        $this->em->flush();
        $vorher = [
            'meta' => $state->meta,
            'payload' => $state->payload,
            'payloadHash' => $state->payloadHash,
            'sizeBytes' => $state->sizeBytes,
            'createdAt' => SyncContract::formatTimestamp($state->createdAt),
            'updatedAt' => SyncContract::formatTimestamp($state->updatedAt),
            'lastAccessAt' => SyncContract::formatTimestamp($state->lastAccessAt),
        ];

        [$status, $body] = $this->metaCall([
            'meta' => 'bmV1',
            'basePayloadHash' => SyncContract::payloadHash(base64_encode('ganz anderer inhalt')),
        ]);

        self::assertSame(412, $status);
        self::assertSame('conflict', $body['error']);
        self::assertSame($vorher['updatedAt'], $body['updatedAt']);
        // Sentinel-Muster: die Closure gibt SyncProblem zurück statt zu werfen,
        // damit wrapInTransaction den EM nicht schließt. Dieser Assert fängt
        // exakt die Regression – kein anderer Pfad in diesem Test würde
        // auf einen geschlossenen EM stoßen.
        self::assertTrue($this->em->isOpen());

        // Alle Felder DIREKT NACH dem 412, VOR dem list-Request prüfen:
        $this->em->clear();
        $nachher = $this->syncStates()->findOneByLocatorAndState(
            SyncContract::locatorHash(self::SYNC_LOCATOR),
            self::SYNC_STATE_ID,
        );
        self::assertSame($vorher['meta'], $nachher->meta);
        self::assertSame($vorher['payload'], $nachher->payload);
        self::assertSame($vorher['payloadHash'], $nachher->payloadHash);
        self::assertSame($vorher['sizeBytes'], $nachher->sizeBytes);
        self::assertSame($vorher['createdAt'], SyncContract::formatTimestamp($nachher->createdAt));
        self::assertSame($vorher['updatedAt'], SyncContract::formatTimestamp($nachher->updatedAt));
        self::assertSame($vorher['lastAccessAt'], SyncContract::formatTimestamp($nachher->lastAccessAt));

        // Prüfliste: nach dem 412 liefert list noch das ALTE meta.
        [$listStatus, $listBody] = $this->call('POST', '/api/v1/sync/list', [
            'apiLevel' => 3, 'locator' => self::SYNC_LOCATOR,
        ]);
        self::assertSame(200, $listStatus);
        self::assertSame($vorher['meta'], $listBody['states'][0]['meta']);
    }

    // ------------------------------------------------ Fehlercodes 400/404/413

    /** basePayloadHash fehlt komplett → 400 (Pflichtfeld, anders als beim PUT). */
    public function testAMissingBasePayloadHashIsBadRequest(): void
    {
        $this->seedSyncState(self::SYNC_LOCATOR, self::SYNC_STATE_ID);

        // Kein basePayloadHash im Array – metaCall lässt ihn im Default weg
        [$status, $body] = $this->metaCall();

        self::assertSame(400, $status);
        self::assertSame('bad-request', $body['error']);
    }

    /** Ein basePayloadHash in falscher Form → 400. */
    public function testAMalformedBasePayloadHashIsBadRequest(): void
    {
        $this->seedSyncState(self::SYNC_LOCATOR, self::SYNC_STATE_ID);

        [$status, $body] = $this->metaCall(['basePayloadHash' => 'zu-kurz']);

        self::assertSame(400, $status);
        self::assertSame('bad-request', $body['error']);
    }

    /**
     * Ein unbekannter stateId → 404, nie anlegen. countByLocator muss
     * unverändert 0 liefern.
     */
    public function testAnUnknownStateIdIsNotFound(): void
    {
        [$status, $body] = $this->metaCall([
            'basePayloadHash' => SyncContract::payloadHash('cGF5bG9hZA=='),
        ]);

        self::assertSame(404, $status);
        self::assertSame('not-found', $body['error']);
        self::assertSame(
            0,
            $this->syncStates()->countByLocator(SyncContract::locatorHash(self::SYNC_LOCATOR)),
        );
    }

    /** meta über 8 KiB → 413. */
    public function testAnOversizedMetaIsTooLarge(): void
    {
        $state = $this->seedSyncState(self::SYNC_LOCATOR, self::SYNC_STATE_ID);

        [$status, $body] = $this->metaCall([
            'basePayloadHash' => $state->payloadHash,
            'meta' => str_repeat('A', SyncContract::MAX_META_BYTES + 1),
        ]);

        self::assertSame(413, $status);
        self::assertSame('too-large', $body['error']);
    }

    /** Ein ungültiger Locator → 400, bevor irgendetwas adressiert wird. */
    public function testAMalformedLocatorIsBadRequest(): void
    {
        [$status, $body] = $this->metaCall([
            'locator' => '../../etc/passwd',
            'basePayloadHash' => SyncContract::payloadHash('cGF5bG9hZA=='),
        ]);

        self::assertSame(400, $status);
        self::assertSame('bad-request', $body['error']);
    }

    /** stateId fehlt → 400 (Pflichtfeld, nicht optional wie beim delete). */
    public function testAMissingStateIdIsBadRequest(): void
    {
        [$status, $body] = $this->call('POST', '/api/v1/sync/meta', [
            'apiLevel' => 3,
            'locator' => self::SYNC_LOCATOR,
            'meta' => 'bmV1',
            'basePayloadHash' => SyncContract::payloadHash('cGF5bG9hZA=='),
        ]);

        self::assertSame(400, $status);
        self::assertSame('bad-request', $body['error']);
    }

    /** stateId in falscher Form (kein 32-Zeichen-Hex) → 400. */
    public function testAMalformedStateIdIsBadRequest(): void
    {
        [$status, $body] = $this->metaCall([
            'stateId' => 'zu-kurz',
            'basePayloadHash' => SyncContract::payloadHash('cGF5bG9hZA=='),
        ]);

        self::assertSame(400, $status);
        self::assertSame('bad-request', $body['error']);
    }

    /** meta fehlt komplett → 400. */
    public function testAMissingMetaIsBadRequest(): void
    {
        [$status, $body] = $this->call('POST', '/api/v1/sync/meta', [
            'apiLevel' => 3,
            'locator' => self::SYNC_LOCATOR,
            'stateId' => self::SYNC_STATE_ID,
            'basePayloadHash' => SyncContract::payloadHash('cGF5bG9hZA=='),
        ]);

        self::assertSame(400, $status);
        self::assertSame('bad-request', $body['error']);
    }

    /** meta als leerer String → 400. */
    public function testAnEmptyMetaIsBadRequest(): void
    {
        [$status, $body] = $this->metaCall([
            'meta' => '',
            'basePayloadHash' => SyncContract::payloadHash('cGF5bG9hZA=='),
        ]);

        self::assertSame(400, $status);
        self::assertSame('bad-request', $body['error']);
    }

    /** meta als kein sauberes Base64 → 400. */
    public function testANonBase64MetaIsBadRequest(): void
    {
        [$status, $body] = $this->metaCall([
            'meta' => 'kein base64 !!!',
            'basePayloadHash' => SyncContract::payloadHash('cGF5bG9hZA=='),
        ]);

        self::assertSame(400, $status);
        self::assertSame('bad-request', $body['error']);
    }

    /** meta exakt 8 KiB (Grenzwert) wird noch angenommen → 200. */
    public function testAMetaExactlyAtTheLimitIsAccepted(): void
    {
        $state = $this->seedSyncState(self::SYNC_LOCATOR, self::SYNC_STATE_ID);

        [$status] = $this->metaCall([
            'basePayloadHash' => $state->payloadHash,
            'meta' => str_repeat('A', SyncContract::MAX_META_BYTES),
        ]);

        self::assertSame(200, $status);
    }

    /** apiLevel fehlt → 400 (Pflicht für alle Sync-Endpunkte). */
    public function testAMissingApiLevelIsBadRequest(): void
    {
        [$status, $body] = $this->call('POST', '/api/v1/sync/meta', [
            'locator' => self::SYNC_LOCATOR,
            'stateId' => self::SYNC_STATE_ID,
            'meta' => 'bmV1',
            'basePayloadHash' => SyncContract::payloadHash('cGF5bG9hZA=='),
        ]);

        self::assertSame(400, $status);
        self::assertSame('bad-request', $body['error']);
    }

    /**
     * Ein Stand gehört seinem Locator – ein Request unter dem falschen Locator
     * sieht den Stand nicht (404) und verändert ihn nicht.
     */
    public function testMetaUnderAnotherLocatorDoesNotTouchTheForeignState(): void
    {
        $fremd = $this->seedSyncState(self::SYNC_OTHER_LOCATOR, self::SYNC_STATE_ID, base64_encode('fremd'));
        $originalMeta = $fremd->meta;

        // SYNC_LOCATOR hat keinen Stand mit dieser stateId → 404
        [$status, $body] = $this->metaCall([
            'basePayloadHash' => SyncContract::payloadHash('cGF5bG9hZA=='),
            'meta' => 'bmV1',
        ]);

        self::assertSame(404, $status);
        self::assertSame('not-found', $body['error']);

        $this->em->refresh($fremd);
        self::assertSame($originalMeta, $fremd->meta);
    }

    // ------------------------------------------------ D-Modifier: kein \n-Bypass (M2)

    /**
     * stateId mit abschließendem \n: ohne /D-Modifier würde PCRE das als
     * gültigen Wert akzeptieren ($ matcht vor \n). Muss 400 liefern.
     */
    public function testAStateIdWithTrailingNewlineIsBadRequest(): void
    {
        [$status, $body] = $this->metaCall([
            'stateId' => self::SYNC_STATE_ID . "\n",
            'basePayloadHash' => SyncContract::payloadHash('cGF5bG9hZA=='),
        ]);

        self::assertSame(400, $status);
        self::assertSame('bad-request', $body['error']);
    }

    /** locator mit abschließendem \n muss 400 liefern (kein \n-Bypass). */
    public function testALocatorWithTrailingNewlineIsBadRequest(): void
    {
        [$status, $body] = $this->metaCall([
            'locator' => self::SYNC_LOCATOR . "\n",
            'basePayloadHash' => SyncContract::payloadHash('cGF5bG9hZA=='),
        ]);

        self::assertSame(400, $status);
        self::assertSame('bad-request', $body['error']);
    }

    /** basePayloadHash mit abschließendem \n muss 400 liefern. */
    public function testABasePayloadHashWithTrailingNewlineIsBadRequest(): void
    {
        $this->seedSyncState(self::SYNC_LOCATOR, self::SYNC_STATE_ID);

        [$status, $body] = $this->metaCall([
            'basePayloadHash' => SyncContract::payloadHash('cGF5bG9hZA==') . "\n",
        ]);

        self::assertSame(400, $status);
        self::assertSame('bad-request', $body['error']);
    }

    // ------------------------------------------------ Retention

    /**
     * Umbenennen frischt lastAccessAt auf – createdAt und updatedAt bleiben
     * unangetastet. Feste, unterschiedliche Zeitpunkte sichern ab, dass ein
     * versehentliches Fortschreiben nicht innerhalb derselben Sekunde verborgen
     * bleibt (Vorbild: LocatorSyncTest::testAReadRefreshesRetentionWithoutTouchingUpdatedAt).
     */
    public function testRenameUpdatesLastAccessAtWithoutTouchingUpdatedAt(): void
    {
        $state = $this->seedSyncState(self::SYNC_LOCATOR, self::SYNC_STATE_ID);
        $state->createdAt = new \DateTimeImmutable('-200 days');
        $state->updatedAt = new \DateTimeImmutable('-100 days');
        $state->lastAccessAt = new \DateTimeImmutable('-400 days');
        $this->em->flush();
        $fixCreatedAt = SyncContract::formatTimestamp($state->createdAt);
        $fixUpdatedAt = SyncContract::formatTimestamp($state->updatedAt);

        $this->metaCall(['basePayloadHash' => $state->payloadHash, 'meta' => 'bmV1']);
        $this->em->refresh($state);

        self::assertGreaterThan(new \DateTimeImmutable('-1 hour'), $state->lastAccessAt);
        // createdAt und updatedAt sekundengenau unverändert:
        self::assertSame($fixUpdatedAt, SyncContract::formatTimestamp($state->updatedAt));
        self::assertSame($fixCreatedAt, SyncContract::formatTimestamp($state->createdAt));
    }

    /**
     * Ein umbenannter Stand wird von index:sync:prune NICHT gelöscht, auch
     * wenn er inhaltlich älter als 12 Monate ist: das Umbenennen hat
     * lastAccessAt neu gesetzt, und der Prune prüft lastAccessAt.
     *
     * createdAt UND updatedAt werden auf –400 Tage gesetzt, damit der Test
     * tatsächlich das behauptete Szenario (inhaltlich alter Stand) herstellt –
     * ein Prune, der fehlerhaft auf updatedAt statt lastAccessAt filterte,
     * würde diesen Stand dann zu Unrecht löschen.
     */
    public function testARenamedStateIsNotPrunedEvenIfContentIsOld(): void
    {
        $locatorHash = SyncContract::locatorHash(self::SYNC_LOCATOR);
        $state = new SyncState($locatorHash, self::SYNC_STATE_ID, 'bWV0YQ==', 'cGF5bG9hZA==');
        $state->createdAt = new \DateTimeImmutable('-400 days');
        $state->updatedAt = new \DateTimeImmutable('-400 days');
        $state->lastAccessAt = new \DateTimeImmutable('-400 days');
        $this->em->persist($state);
        $this->em->flush();
        $stateId = (int) $state->id;
        $fixUpdatedAt = SyncContract::formatTimestamp($state->updatedAt);

        // Umbenennen setzt lastAccessAt auf jetzt, updatedAt bleibt alt
        $this->metaCall(['basePayloadHash' => $state->payloadHash, 'meta' => 'bmV1']);

        $tester = new CommandTester((new Application(static::$kernel))->find('index:sync:prune'));
        $tester->execute([]);

        $this->em->clear();
        $gefunden = $this->em->find(SyncState::class, $stateId);
        self::assertNotNull($gefunden);
        // updatedAt ist durch das Umbenennen NICHT verändert worden
        self::assertSame($fixUpdatedAt, SyncContract::formatTimestamp($gefunden->updatedAt));
    }

    /**
     * Ohne Umbenennen fällt ein Stand mit lastAccessAt > 12 Monate dem Prune
     * zum Opfer – Gegenbeweis zum obigen Test.
     */
    public function testAnUntouchedOldStateIsPrunedAfterTwelveMonths(): void
    {
        $locatorHash = SyncContract::locatorHash(self::SYNC_OTHER_LOCATOR);
        $state = new SyncState($locatorHash, self::SYNC_STATE_ID, 'bWV0YQ==', 'cGF5bG9hZA==');
        $state->lastAccessAt = new \DateTimeImmutable('-400 days');
        $this->em->persist($state);
        $this->em->flush();
        $stateId = (int) $state->id;

        $tester = new CommandTester((new Application(static::$kernel))->find('index:sync:prune'));
        $tester->execute([]);

        $this->em->clear();
        self::assertNull($this->em->find(SyncState::class, $stateId));
    }

    // ------------------------------------------------ 404 isoliert Geschwister

    /**
     * Ein 404 auf eine unbekannte stateId darf lastAccessAt der anderen Stände
     * desselben Locators NICHT verändern – kein versehentlicher touch bei einer
     * fehlgeschlagenen Anfrage (aktuell korrekt, aber bisher ungesichert).
     */
    public function testA404OnAnUnknownStateIdDoesNotTouchSiblingStates(): void
    {
        $geschwister = $this->seedSyncState(self::SYNC_LOCATOR, 'ffffffffffffffffffffffffffffffff');
        $geschwister->lastAccessAt = new \DateTimeImmutable('-30 days');
        $this->em->flush();
        $fixLastAccess = SyncContract::formatTimestamp($geschwister->lastAccessAt);

        // Unbekannte stateId unter demselben Locator → 404
        [$status] = $this->metaCall([
            'stateId' => '00000000000000000000000000000000',
            'basePayloadHash' => SyncContract::payloadHash('cGF5bG9hZA=='),
        ]);
        self::assertSame(404, $status);

        $this->em->refresh($geschwister);
        self::assertSame($fixLastAccess, SyncContract::formatTimestamp($geschwister->lastAccessAt));
    }

    // ------------------------------------------------ features in list

    /** /sync/list meldet features: ["sync-meta"] – gegen das Vertrags-Literal, nicht die Konstante. */
    public function testListContainsFeaturesSyncMeta(): void
    {
        [$status, $body] = $this->call('POST', '/api/v1/sync/list', [
            'apiLevel' => 3, 'locator' => self::SYNC_LOCATOR,
        ]);

        self::assertSame(200, $status);
        self::assertArrayHasKey('features', $body);
        self::assertSame(['sync-meta'], $body['features']);
    }
}
