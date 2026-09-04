<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Api\ApiLevel;

/**
 * Der apiLevel, den der Index gegenüber der Extension meldet, ist ein
 * Versprechen: Level 3 heißt »die vier /api/v1/sync/*-Endpunkte antworten«.
 * Dieser Test hält beides zusammen – die Zahl und ihre Deckung.
 */
final class ApiLevelTest extends ApiTestCase
{
    public function testTheIndexReportsLevelThree(): void
    {
        self::assertSame(3, ApiLevel::IMPLEMENTED);
    }

    /** Die Zahl steht so auch in der Antwort des Update-Checks. */
    public function testTheUpdateCheckAnswerCarriesTheLevel(): void
    {
        $this->client->request('POST', '/api/v1/updates', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['apiLevel' => 3, 'entries' => []], JSON_THROW_ON_ERROR));

        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame(3, $body['apiLevel']);
    }

    /**
     * Und die Deckung: alle vier Endpunkte antworten. Ein Level ohne Deckung
     * waere schlimmer als ein zu niedriges - der Client richtet sein
     * Verhalten danach aus.
     */
    public function testAllFourSyncEndpointsAnswer(): void
    {
        $locator = 'zoogXw2lwmt_ZqFnRu-lFOWYxyJaU2kpxfunpy3Umsk';
        $stateId = '0123456789abcdef0123456789abcdef';

        foreach ([
            ['PUT', '/api/v1/sync/state', ['apiLevel' => 3, 'locator' => $locator, 'stateId' => $stateId, 'meta' => 'bQ==', 'payload' => 'cA==']],
            ['POST', '/api/v1/sync/list', ['apiLevel' => 3, 'locator' => $locator]],
            ['POST', '/api/v1/sync/get', ['apiLevel' => 3, 'locator' => $locator, 'stateId' => $stateId]],
            ['POST', '/api/v1/sync/delete', ['apiLevel' => 3, 'locator' => $locator, 'stateId' => $stateId]],
        ] as [$method, $path, $body]) {
            $this->client->request($method, $path, server: ['CONTENT_TYPE' => 'application/json'], content: json_encode($body, JSON_THROW_ON_ERROR));
            self::assertSame(200, $this->client->getResponse()->getStatusCode(), $method . ' ' . $path);
        }
    }
}
