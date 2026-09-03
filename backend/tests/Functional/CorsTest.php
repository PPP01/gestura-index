<?php

declare(strict_types=1);

namespace App\Tests\Functional;

final class CorsTest extends ApiTestCase
{
    public function testPreflightIsAnswered(): void
    {
        $this->client->request('OPTIONS', '/api/v1/entries', server: [
            'HTTP_ORIGIN' => 'https://example.org',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        ]);
        $response = $this->client->getResponse();
        self::assertSame(204, $response->getStatusCode());
        self::assertSame('*', $response->headers->get('Access-Control-Allow-Origin'));
        self::assertStringContainsString('Authorization', (string) $response->headers->get('Access-Control-Allow-Headers'));
    }

    /**
     * Vertrag R2, Abschnitt »Update check / CORS«: Firefox MV3 schickt den
     * Preflight von moz-extension://… – der Endpunkt muss ihn offen beantworten.
     */
    public function testUpdateCheckPreflightMatchesContract(): void
    {
        $this->client->request('OPTIONS', '/api/v1/updates', server: [
            'HTTP_ORIGIN' => 'moz-extension://3f1c2a6e-0000-4000-8000-000000000000',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'content-type',
        ]);
        $response = $this->client->getResponse();
        self::assertSame(204, $response->getStatusCode());
        self::assertSame('*', $response->headers->get('Access-Control-Allow-Origin'));
        self::assertStringContainsString('POST', (string) $response->headers->get('Access-Control-Allow-Methods'));
        self::assertStringContainsString('OPTIONS', (string) $response->headers->get('Access-Control-Allow-Methods'));
        self::assertStringContainsString('Content-Type', (string) $response->headers->get('Access-Control-Allow-Headers'));
    }

    public function testUnknownApiRouteYieldsProblemJsonWithCors(): void
    {
        $this->client->request('GET', '/api/v1/gibt-es-nicht');
        $response = $this->client->getResponse();
        self::assertSame(404, $response->getStatusCode());
        self::assertSame('application/problem+json', $response->headers->get('Content-Type'));
        self::assertSame('*', $response->headers->get('Access-Control-Allow-Origin'));
    }
}
