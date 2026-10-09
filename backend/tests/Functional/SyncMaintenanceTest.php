<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Api\SyncContract;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Wartungsmodus für /api/v1/sync/*: Flag-Datei steuert den Subscriber.
 */
final class SyncMaintenanceTest extends ApiTestCase
{
    private string $flagFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->flagFile = static::getContainer()
            ->getParameter('app.sync_maintenance_file');
        // Sicherstellen, dass kein Flag aus einem früheren Test bleibt.
        if (is_file($this->flagFile)) {
            unlink($this->flagFile);
        }
    }

    protected function tearDown(): void
    {
        if (is_file($this->flagFile)) {
            @unlink($this->flagFile);
        }
        parent::tearDown();
    }

    private function enableMaintenance(?string $until = null): void
    {
        $dir = \dirname($this->flagFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0o755, true);
        }
        file_put_contents($this->flagFile, $until ?? '');
    }

    // --- Wartung auf Ping ---

    public function testPingReturns503DuringMaintenance(): void
    {
        $this->enableMaintenance();
        $this->client->request('GET', '/api/v1/sync/ping');

        $response = $this->client->getResponse();
        self::assertSame(503, $response->getStatusCode());
        self::assertStringStartsWith(
            'application/json',
            (string) $response->headers->get('Content-Type'),
        );
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('maintenance', $body['error']);
        self::assertArrayNotHasKey('until', $body);
    }

    public function testPingReturns503WithUntilDuringMaintenance(): void
    {
        // +02:00 in der Flag-Datei – die Antwort muss auf +00:00
        // normiert sein (E7, Terra N1).
        $dt = new \DateTimeImmutable('+2 hours', new \DateTimeZone('+02:00'));
        $this->enableMaintenance(
            $dt->format(\DateTimeInterface::ATOM),
        );

        $this->client->request('GET', '/api/v1/sync/ping');

        $response = $this->client->getResponse();
        self::assertSame(503, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('maintenance', $body['error']);
        self::assertArrayHasKey('until', $body);
        // Muss auf UTC normiert sein.
        self::assertStringEndsWith('+00:00', $body['until']);
        self::assertNotEmpty($response->headers->get('Retry-After'));
        // Retry-After muss eine positive Zahl sein.
        self::assertGreaterThan(
            0,
            (int) $response->headers->get('Retry-After'),
        );
    }

    // --- Wartung auf ALLEN Sync-Endpunkten (Terra Niedrig) ---

    /**
     * @return iterable<string, array{string, string, array<string, mixed>}>
     */
    public static function syncEndpointProvider(): iterable
    {
        $loc = self::SYNC_LOCATOR;
        $sid = self::SYNC_STATE_ID;

        yield 'POST /sync/list' => ['POST', '/api/v1/sync/list', [
            'apiLevel' => 3, 'locator' => $loc,
        ]];
        yield 'PUT /sync/state' => ['PUT', '/api/v1/sync/state', [
            'apiLevel' => 3, 'locator' => $loc, 'stateId' => $sid,
            'meta' => 'bWV0YQ==', 'payload' => 'cGF5bG9hZA==',
        ]];
        yield 'POST /sync/get' => ['POST', '/api/v1/sync/get', [
            'apiLevel' => 3, 'locator' => $loc, 'stateId' => $sid,
        ]];
        yield 'POST /sync/meta' => ['POST', '/api/v1/sync/meta', [
            'apiLevel' => 3, 'locator' => $loc, 'stateId' => $sid,
            'meta' => 'bWV0YQ==',
        ]];
        yield 'POST /sync/delete' => ['POST', '/api/v1/sync/delete', [
            'apiLevel' => 3, 'locator' => $loc, 'stateId' => $sid,
        ]];
        yield 'GET /sync/ping' => ['GET', '/api/v1/sync/ping', []];
    }

    /**
     * Alle /api/v1/sync/-Endpunkte müssen in Wartung 503 liefern.
     *
     * @param array<string, mixed> $body
     */
    #[DataProvider('syncEndpointProvider')]
    public function testAllSyncEndpointsReturn503DuringMaintenance(
        string $method,
        string $path,
        array $body,
    ): void {
        $this->enableMaintenance();

        if ($body !== []) {
            $this->api($method, $path, $body);
        } else {
            $this->client->request($method, $path);
        }

        self::assertSame(
            503,
            $this->client->getResponse()->getStatusCode(),
            "$method $path muss in Wartung 503 liefern",
        );
        $json = json_decode(
            (string) $this->client->getResponse()->getContent(),
            true,
        );
        self::assertSame('maintenance', $json['error']);
    }

    // --- /api/v1/updates bleibt unberührt ---

    public function testUpdatesEndpointIsNotAffectedByMaintenance(): void
    {
        $this->enableMaintenance();
        $this->api('POST', '/api/v1/updates', [
            'apiLevel' => 3,
            'entries' => [],
        ]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
    }

    // --- OPTIONS in Wartung → 204, Allow-Methods enthält GET ---

    public function testOptionsPreflightReturns204DuringMaintenance(): void
    {
        $this->enableMaintenance();
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

    // --- E2: Abgelaufenes until => weiter 503 ohne until/Retry-After ---

    public function testExpiredUntilStillReturnsMaintenance(): void
    {
        $past = (new \DateTimeImmutable('-1 hour'))
            ->format(\DateTimeInterface::ATOM);
        $this->enableMaintenance($past);

        $this->client->request('GET', '/api/v1/sync/ping');

        $response = $this->client->getResponse();
        self::assertSame(503, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('maintenance', $body['error']);
        self::assertArrayNotHasKey('until', $body);
        self::assertNull($response->headers->get('Retry-After'));
    }

    // --- Ungültiger Dateiinhalt → Wartung ohne until ---

    public function testInvalidFlagContentMeansMaintenanceWithoutUntil(): void
    {
        $this->enableMaintenance('das ist kein datum');

        $this->client->request('GET', '/api/v1/sync/ping');

        $response = $this->client->getResponse();
        self::assertSame(503, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('maintenance', $body['error']);
        self::assertArrayNotHasKey('until', $body);
    }

    /** »tomorrow« ist kein ATOM – Wartung ohne until (Terra M1). */
    public function testTomorrowInFlagIsTreatedAsInvalid(): void
    {
        $this->enableMaintenance('tomorrow');

        $this->client->request('GET', '/api/v1/sync/ping');

        $response = $this->client->getResponse();
        self::assertSame(503, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('maintenance', $body['error']);
        self::assertArrayNotHasKey('until', $body);
    }

    /**
     * Nullbyte in der Flag-Datei darf keinen 500 erzeugen (Astra 2,
     * PHP 8.5 ValueError in createFromFormat). Muss 503 maintenance
     * ohne until liefern.
     */
    public function testNullbyteInFlagGivesMaintenance(): void
    {
        $this->enableMaintenance(
            "2030-01-01T12:00:00+00:00\x00junk",
        );

        $this->client->request('GET', '/api/v1/sync/ping');

        $response = $this->client->getResponse();
        self::assertSame(503, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('maintenance', $body['error']);
        self::assertArrayNotHasKey('until', $body);
    }

    // --- Ohne Flag-Datei kein Wartungsmodus ---

    public function testNoFlagFileMeansNoMaintenance(): void
    {
        // setUp() hat die Datei bereits entfernt.
        $this->client->request('GET', '/api/v1/sync/ping');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
    }

    // --- Wartung verbraucht kein Rate-Limit (Terra M3, Gemini H-1) ---

    public function testMaintenanceDoesNotConsumeRateLimitTokens(): void
    {
        // Pool bis auf 1 Token erschöpfen – wenn die 10 Wartungs-
        // requests fälschlicherweise Tokens verbrauchen, wäre der
        // Pool leer und der Ping nach der Wartung 429 statt 200.
        $factory = static::getContainer()->get('limiter.sync_ping');
        $factory->create('127.0.0.1')->consume(999);

        $this->enableMaintenance();

        // 10 Requests in Wartung – keiner zählt gegen den Limiter.
        for ($i = 0; $i < 10; $i++) {
            $this->client->request('GET', '/api/v1/sync/ping');
            self::assertSame(503, $this->client->getResponse()->getStatusCode());
        }

        // Wartung beenden – der eine verbleibende Token muss reichen.
        unlink($this->flagFile);
        $this->client->request('GET', '/api/v1/sync/ping');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
    }

    /** E9: Slash-Variante in Wartung liefert 503 ohne Location. */
    public function testSlashVariantReturns503InMaintenance(): void
    {
        $this->enableMaintenance();
        $this->client->request('GET', '/api/v1/sync/ping/');

        $response = $this->client->getResponse();
        self::assertSame(503, $response->getStatusCode());
        self::assertNull(
            $response->headers->get('Location'),
            'Kein Location-Header – kein Redirect',
        );
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('maintenance', $body['error']);
    }

    /**
     * Wartung auf Ping liefert application/json, nicht problem+json.
     * Dieser Test läuft über den vollen HTTP-Stack (Subscriber setzt
     * die Response direkt, ProblemJsonSubscriber ist nicht beteiligt).
     */
    public function testMaintenanceResponseIsNotProblemJson(): void
    {
        $this->enableMaintenance();
        $this->client->request('GET', '/api/v1/sync/ping');

        $response = $this->client->getResponse();
        self::assertSame(503, $response->getStatusCode());
        self::assertStringStartsWith(
            'application/json',
            (string) $response->headers->get('Content-Type'),
        );
        self::assertStringNotContainsString(
            'problem',
            (string) $response->headers->get('Content-Type'),
        );
    }

    /** Cache-Control: no-store auf der Wartungsantwort (Gemini N-4). */
    public function testMaintenanceResponseHasNoStoreHeader(): void
    {
        $this->enableMaintenance();
        $this->client->request('GET', '/api/v1/sync/ping');

        self::assertStringContainsString(
            'no-store',
            (string) $this->client->getResponse()->headers->get('Cache-Control'),
        );
    }
}
