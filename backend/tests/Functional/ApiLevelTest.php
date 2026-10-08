<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Api\SyncContract;

/**
 * Der apiLevel, den der Index gegenüber der Extension meldet, ist ein
 * Versprechen: Level 3 heißt »die /api/v1/sync/*-Endpunkte antworten«.
 * Dieser Test hält beides zusammen – die Zahl und ihre Deckung.
 */
final class ApiLevelTest extends ApiTestCase
{
    /** Die gemeldete Zahl – geprüft an der echten Antwort, nicht an der Konstanten. */
    public function testTheUpdateCheckAnswerCarriesTheLevel(): void
    {
        $this->api('POST', '/api/v1/updates', ['apiLevel' => 3, 'entries' => []]);

        self::assertSame(3, $this->json()['apiLevel']);
    }

    /**
     * Und die Deckung: alle Sync-Endpunkte antworten. Ein Level ohne Deckung
     * waere schlimmer als ein zu niedriges - der Client richtet sein
     * Verhalten danach aus.
     */
    public function testAllSyncEndpointsAnswer(): void
    {
        // PUT zuerst: legt den Stand an, auf den meta und get zugreifen.
        foreach ([
            ['PUT', '/api/v1/sync/state', ['apiLevel' => 3, 'locator' => self::SYNC_LOCATOR, 'stateId' => self::SYNC_STATE_ID, 'meta' => 'bQ==', 'payload' => 'cA==']],
            ['POST', '/api/v1/sync/meta', ['apiLevel' => 3, 'locator' => self::SYNC_LOCATOR, 'stateId' => self::SYNC_STATE_ID, 'meta' => 'bQ==', 'basePayloadHash' => SyncContract::payloadHash('cA==')]],
            ['POST', '/api/v1/sync/list', ['apiLevel' => 3, 'locator' => self::SYNC_LOCATOR]],
            ['POST', '/api/v1/sync/get', ['apiLevel' => 3, 'locator' => self::SYNC_LOCATOR, 'stateId' => self::SYNC_STATE_ID]],
            ['POST', '/api/v1/sync/delete', ['apiLevel' => 3, 'locator' => self::SYNC_LOCATOR, 'stateId' => self::SYNC_STATE_ID]],
        ] as [$method, $path, $body]) {
            $this->api($method, $path, $body);
            self::assertSame(200, $this->client->getResponse()->getStatusCode(), $method . ' ' . $path);
        }
    }
}
