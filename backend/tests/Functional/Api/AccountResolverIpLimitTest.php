<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Exception\ApiProblem;
use App\Repository\AccountRepository;
use App\Service\AccountResolver;
use App\Service\AccountTokenService;
use App\Service\RateLimitGuard;
use App\Tests\Functional\ApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

/**
 * Deckt Spec §11 ab: das Per-IP-Limit »account_auth_ip« ist die einzige
 * DoS-Kontrolle des Konto-Auth-Realms und muss VOR der teuren Argon2id-
 * Dummy-Verifikation greifen – sonst könnte ein Angreifer per
 * Selector-Rotation von derselben IP beliebig viele Argon2id-Prüfungen
 * auslösen. Analog zu SubmitterResolverTest (Unit-Test), hier aber als
 * Functional-Test: AccountRepository ist final und daher nicht per
 * createStub() doppelbar, weshalb die echte Repository-/EM-Instanz aus dem
 * Container verwendet wird. Der Rate-Limiter selbst wird trotzdem isoliert
 * mit InMemoryStorage und einem klein gewählten Limit konstruiert (nicht der
 * Container-Limiter mit dem großzügigen Test-Limit aus rate_limiter.yaml),
 * damit das Limit schnell und deterministisch erreicht wird.
 */
final class AccountResolverIpLimitTest extends ApiTestCase
{
    private function resolver(int $ipLimit): AccountResolver
    {
        $ipFactory = new RateLimiterFactory(
            ['id' => 'account_auth_ip', 'policy' => 'fixed_window', 'limit' => $ipLimit, 'interval' => '1 hour'],
            new InMemoryStorage(),
        );

        return new AccountResolver(
            new AccountTokenService(),
            static::getContainer()->get(AccountRepository::class),
            new RateLimitGuard(),
            $ipFactory,
            static::getContainer()->get(EntityManagerInterface::class),
        );
    }

    private function request(string $ip, int $selectorSeed): Request
    {
        // Gültiges Token-Format mit je Aufruf variierendem, unbekanntem
        // Selector (16 hex) – simuliert Selector-Rotation eines Angreifers.
        $selector = str_pad(dechex($selectorSeed), 16, '0', STR_PAD_LEFT);
        $token = sprintf('gacc_%s_%s', $selector, str_repeat('A', 43));

        return Request::create('/api/account/me', 'GET', server: [
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
        // Argon2id-Verifikation → 429 statt erneuter Dummy-Hash-Prüfung.
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
