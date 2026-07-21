<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Exception\ApiProblem;
use App\Repository\SubmitterRepository;
use App\Service\EditTokenService;
use App\Service\RateLimitGuard;
use App\Service\SubmitterResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

/**
 * Isolierter Test der Rate-Limit-Absicherung des Token-Auth-Auflösers:
 * belegt, dass ein Angreifer die teure Argon2id-Dummy-Verifikation nicht
 * durch Wechseln des Selectors (eigener Bucket je Selector) unbegrenzt
 * auslösen kann – ein unabhängiges Per-IP-Limit muss davor greifen.
 */
final class SubmitterResolverTest extends TestCase
{
    private function resolver(int $ipLimit): SubmitterResolver
    {
        $submitters = $this->createStub(SubmitterRepository::class);
        $submitters->method('findOneBy')->willReturn(null); // Selector immer unbekannt → Dummy-Hash-Pfad

        $ipFactory = new RateLimiterFactory(
            ['id' => 'token_auth_ip', 'policy' => 'fixed_window', 'limit' => $ipLimit, 'interval' => '1 hour'],
            new InMemoryStorage(),
        );
        $selectorFactory = new RateLimiterFactory(
            ['id' => 'token_auth', 'policy' => 'fixed_window', 'limit' => 1000, 'interval' => '1 hour'],
            new InMemoryStorage(),
        );

        return new SubmitterResolver(
            new EditTokenService(),
            $submitters,
            new RateLimitGuard(),
            $selectorFactory,
            $ipFactory,
        );
    }

    private function request(string $ip, int $selectorSeed): Request
    {
        // Gültiges Token-Format mit je Aufruf variierendem Selector (16 hex).
        $selector = str_pad(dechex($selectorSeed), 16, '0', STR_PAD_LEFT);
        $token = sprintf('gsti_%s_%s', $selector, str_repeat('A', 43));

        return Request::create('/api/v1/entries', 'POST', server: [
            'REMOTE_ADDR' => $ip,
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ]);
    }

    public function testPerIpLimitBlocksSelectorRotationRegardlessOfSelector(): void
    {
        $resolver = $this->resolver(ipLimit: 3);

        // Bis zum IP-Limit: jeder Versuch scheitert regulär mit 401 (Token ungültig).
        for ($i = 0; $i < 3; ++$i) {
            try {
                $resolver->resolve($this->request('9.9.9.9', $i));
                self::fail('401 für ungültiges Token erwartet');
            } catch (ApiProblem $e) {
                self::assertSame(401, $e->getStatusCode(), "Versuch $i");
            }
        }

        // Weiterer Versuch mit NEUEM Selector: das Per-IP-Limit greift VOR der
        // Hash-Prüfung → 429 statt erneuter Argon2id-Verifikation.
        try {
            $resolver->resolve($this->request('9.9.9.9', 99));
            self::fail('429 durch Per-IP-Limit erwartet');
        } catch (ApiProblem $e) {
            self::assertSame(429, $e->getStatusCode());
        }
    }

    public function testDifferentIpsAreLimitedIndependently(): void
    {
        $resolver = $this->resolver(ipLimit: 1);

        // Erste IP verbraucht ihr Kontingent (401), zweite IP ist unbeeinflusst (ebenfalls 401, kein 429).
        foreach (['1.1.1.1', '2.2.2.2'] as $ip) {
            try {
                $resolver->resolve($this->request($ip, 0));
                self::fail('401 erwartet');
            } catch (ApiProblem $e) {
                self::assertSame(401, $e->getStatusCode(), $ip);
            }
        }
    }
}
