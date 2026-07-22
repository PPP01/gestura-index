<?php
declare(strict_types=1);
namespace App\Tests\Functional\Admin;

/**
 * Prüft die CORS-Differenzierung (credentialed für /api/admin, "*" für die
 * öffentliche API) sowie den CSRF-Header-Zwang (X-Requested-With) für
 * zustandsändernde Admin-Requests.
 */
final class CorsCsrfTest extends AdminTestCase
{
    public function testAdminCorsIsCredentialed(): void
    {
        $this->client->request('OPTIONS', '/api/admin/auth/options', server: [
            'HTTP_ORIGIN' => 'https://gestura.eu',
        ]);

        $response = $this->client->getResponse();
        self::assertSame('https://gestura.eu', $response->headers->get('Access-Control-Allow-Origin'));
        self::assertSame('true', $response->headers->get('Access-Control-Allow-Credentials'));
    }

    public function testPublicCorsStaysWildcard(): void
    {
        $this->client->request('OPTIONS', '/api/v1/entries', server: [
            'HTTP_ORIGIN' => 'https://example.com',
        ]);

        $response = $this->client->getResponse();
        self::assertSame('*', $response->headers->get('Access-Control-Allow-Origin'));
        self::assertFalse($response->headers->has('Access-Control-Allow-Credentials'));
    }

    public function testAdminCorsHeaderPresentOnRealNonPreflightResponse(): void
    {
        // Kein OPTIONS-Preflight, sondern ein echter (unauthentifizierter)
        // Request — die CORS-Header müssen dennoch über den RESPONSE-Zweig
        // gesetzt werden, nicht nur über den REQUEST-Preflight-Kurzschluss.
        $this->client->request('GET', '/api/admin/auth/me', server: array_merge($this->hdr(), [
            'HTTP_ORIGIN' => 'https://gestura.eu',
        ]));

        self::assertResponseStatusCodeSame(401);
        $response = $this->client->getResponse();
        self::assertSame('https://gestura.eu', $response->headers->get('Access-Control-Allow-Origin'));
        self::assertSame('true', $response->headers->get('Access-Control-Allow-Credentials'));
    }

    public function testStateChangingAdminNeedsCsrfHeader(): void
    {
        // Bewusst OHNE X-Requested-With — nur Content-Type, so wie ein
        // Cross-Site-HTML-Formular es (per <form>) noch hinbekäme.
        $this->client->request('POST', '/api/admin/auth/login', server: [
            'CONTENT_TYPE' => 'application/json',
        ], content: json_encode(['id' => 'cred-1'], JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(403);
    }
}
