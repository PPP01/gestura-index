<?php
declare(strict_types=1);
namespace App\Tests\Support;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Nur in der Test-Umgebung geladene, geschützte Admin-Route. Task 5 baut reine
 * Security-Infrastruktur — echte Admin-Controller (Queue, Users …) entstehen
 * erst in späteren Tasks. Damit der FirewallTest den 401-Pfad der Firewall
 * jetzt schon nachweisen kann, braucht er eine real geroutete Route unterhalb
 * von ^/api/admin (sonst greift der Router-404 vor der Firewall).
 */
final class FirewallProbeController
{
    #[Route('/api/admin/_probe', name: 'test_admin_probe', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        return new JsonResponse(['ok' => true]);
    }
}
