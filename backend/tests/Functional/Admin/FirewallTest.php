<?php
declare(strict_types=1);
namespace App\Tests\Functional\Admin;

/**
 * Weist nach, dass die Admin-Firewall (^/api/admin) einen Request ohne
 * gültige Session abweist. Getestet gegen eine test-only Sonde
 * (App\Tests\Support\FirewallProbeController, Route /api/admin/_probe), weil
 * in dieser reinen Security-Task noch keine echten Admin-Controller existieren
 * und der Router sonst mit 404 antwortet, bevor die Firewall greift.
 */
final class FirewallTest extends AdminTestCase
{
    public function testProtectedAdminRouteRequiresAuthentication(): void
    {
        $this->client->request('GET', '/api/admin/_probe');
        self::assertResponseStatusCodeSame(401);
        self::assertSame('application/problem+json', $this->client->getResponse()->headers->get('Content-Type'));
    }

    public function testUnauthenticatedResponseCarriesProblemJsonBody(): void
    {
        $this->client->request('GET', '/api/admin/_probe');
        self::assertResponseStatusCodeSame(401);
        $body = json_decode((string) $this->client->getResponse()->getContent(), true, 64, JSON_THROW_ON_ERROR);
        self::assertSame('Authentication required', $body['title']);
        self::assertSame(401, $body['status']);
    }
}
