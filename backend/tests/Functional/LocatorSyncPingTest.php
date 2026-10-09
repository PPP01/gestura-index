<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Api\SyncContract;

/**
 * GET /api/v1/sync/ping – Erreichbarkeit und Features.
 */
final class LocatorSyncPingTest extends ApiTestCase
{
    public function testPingReturnsOkWithFeaturesAndNoStore(): void
    {
        $this->client->request('GET', '/api/v1/sync/ping');

        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode());
        self::assertStringStartsWith(
            'application/json',
            (string) $response->headers->get('Content-Type'),
        );
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        $body = json_decode(
            (string) $response->getContent(),
            true,
            8,
            JSON_THROW_ON_ERROR,
        );
        self::assertSame('ok', $body['status']);
        self::assertSame(SyncContract::FEATURES, $body['features']);
    }

    /** Die Featureliste des Pings ist dieselbe wie in POST /sync/list. */
    public function testPingFeaturesMatchListFeatures(): void
    {
        $this->client->request('GET', '/api/v1/sync/ping');
        $pingFeatures = json_decode(
            (string) $this->client->getResponse()->getContent(),
            true,
        )['features'];

        $this->api('POST', '/api/v1/sync/list', [
            'apiLevel' => 3,
            'locator' => self::SYNC_LOCATOR,
        ]);
        $listFeatures = json_decode(
            (string) $this->client->getResponse()->getContent(),
            true,
        )['features'];

        self::assertSame($pingFeatures, $listFeatures);
    }

    /** 429 in der Vertragsform, nicht RFC 7807. */
    public function testPingRateLimitUsesContractShape(): void
    {
        // Den Limiter im Test-Modus auf 1000/h gesetzt; wir konsumieren
        // stattdessen den Limiter-Pool direkt, um den Test deterministisch
        // zu halten, ohne 1000 Requests zu feuern.
        $factory = static::getContainer()->get('limiter.sync_ping');
        $limiter = $factory->create('127.0.0.1');
        $limiter->consume(1000); // Pool erschöpfen

        $this->client->request('GET', '/api/v1/sync/ping');

        $response = $this->client->getResponse();
        self::assertSame(429, $response->getStatusCode());
        self::assertStringStartsWith(
            'application/json',
            (string) $response->headers->get('Content-Type'),
        );
        self::assertNotEmpty($response->headers->get('Retry-After'));

        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('rate-limited', $body['error']);
    }

    /**
     * E9: Die Slash-Variante liefert denselben Ping, kein 301.
     */
    public function testPingWithTrailingSlashReturnsOkWithoutRedirect(): void
    {
        $this->client->request('GET', '/api/v1/sync/ping/');

        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode());
        self::assertNull(
            $response->headers->get('Location'),
            'Kein Location-Header – kein Redirect',
        );

        $body = json_decode(
            (string) $response->getContent(),
            true,
            8,
            JSON_THROW_ON_ERROR,
        );
        self::assertSame('ok', $body['status']);
        self::assertSame(SyncContract::FEATURES, $body['features']);
    }

    /** E9: HEAD auf /ping/ liefert 200, kein 3xx. */
    public function testHeadOnSlashVariantReturnsOkWithoutRedirect(): void
    {
        $this->client->request('HEAD', '/api/v1/sync/ping/');

        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode());
        self::assertNull(
            $response->headers->get('Location'),
            'Kein Location-Header – kein Redirect',
        );
    }

    /**
     * GET steht in Access-Control-Allow-Methods der öffentlichen API
     * (CorsSubscriber); der Ping erbt das, keine CORS-Änderung nötig.
     */
    public function testPreflightForPingAllowsGet(): void
    {
        $this->client->request('OPTIONS', '/api/v1/sync/ping', server: [
            'HTTP_ORIGIN' => 'moz-extension://test',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
        ]);

        $response = $this->client->getResponse();
        self::assertSame(204, $response->getStatusCode());
        self::assertStringContainsString(
            'GET',
            (string) $response->headers->get('Access-Control-Allow-Methods'),
        );
    }
}
