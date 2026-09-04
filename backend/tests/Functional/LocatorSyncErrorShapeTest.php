<?php

declare(strict_types=1);

namespace App\Tests\Functional;

/**
 * Die Sync-Endpunkte antworten in der Vertragsform { "error": "<code>" }, der
 * Rest der API weiterhin in RFC 7807. Beides nebeneinander ist Absicht – der
 * Vertrag schreibt die erste Form wörtlich vor.
 */
final class LocatorSyncErrorShapeTest extends ApiTestCase
{
    public function testASyncErrorUsesTheContractShape(): void
    {
        $this->client->request('POST', '/api/v1/sync/list', server: ['CONTENT_TYPE' => 'application/json'], content: 'kein json');

        $response = $this->client->getResponse();
        self::assertSame(400, $response->getStatusCode());
        self::assertStringStartsWith('application/json', (string) $response->headers->get('Content-Type'));
        self::assertSame(['error' => 'bad-request'], json_decode((string) $response->getContent(), true));
    }

    /** Der Rest der API bleibt bei RFC 7807 – der Umbau darf nicht durchschlagen. */
    public function testTheRestOfTheApiStillAnswersProblemJson(): void
    {
        $this->client->request('POST', '/api/v1/updates', server: ['CONTENT_TYPE' => 'application/json'], content: 'kein json');

        $response = $this->client->getResponse();
        self::assertSame(400, $response->getStatusCode());
        self::assertStringStartsWith('application/problem+json', (string) $response->headers->get('Content-Type'));
        self::assertArrayHasKey('title', json_decode((string) $response->getContent(), true));
    }
}
