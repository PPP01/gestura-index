<?php
declare(strict_types=1);
namespace App\Tests\Functional\Api;

use App\Entity\Account;
use App\Exception\ApiProblem;
use App\Service\AccountResolver;
use App\Service\AccountTokenService;
use App\Tests\Functional\ApiTestCase;
use Symfony\Component\HttpFoundation\Request;

final class AccountResolverTest extends ApiTestCase
{
    /** @return string Klartext-Token */
    private function seedAccount(): string
    {
        $gen = (new AccountTokenService())->generate();
        $account = new Account($gen->selector, $gen->hash);
        $this->em->persist($account);
        $this->em->flush();

        return $gen->token;
    }

    private function resolver(): AccountResolver
    {
        return static::getContainer()->get(AccountResolver::class);
    }

    private function requestWithToken(?string $token): Request
    {
        $req = Request::create('/api/account/me');
        if ($token !== null) {
            $req->headers->set('Authorization', 'Bearer ' . $token);
        }

        return $req;
    }

    public function testResolvesValidTokenAndUpdatesLastSeen(): void
    {
        $token = $this->seedAccount();
        $account = $this->resolver()->resolve($this->requestWithToken($token));
        self::assertNotNull($account);
        self::assertGreaterThanOrEqual($account->createdAt->getTimestamp(), $account->lastSeenAt->getTimestamp());
    }

    public function testNoHeaderReturnsNull(): void
    {
        self::assertNull($this->resolver()->resolve($this->requestWithToken(null)));
    }

    public function testUnknownSelectorThrows401(): void
    {
        $this->seedAccount();
        $bogus = 'gacc_' . str_repeat('a', 16) . '_' . str_repeat('b', 43);

        try {
            $this->resolver()->resolve($this->requestWithToken($bogus));
            self::fail('Expected ApiProblem was not thrown');
        } catch (ApiProblem $e) {
            self::assertSame(401, $e->getStatusCode());
        }
    }

    public function testRequireAccountThrowsWithoutToken(): void
    {
        try {
            $this->resolver()->requireAccount($this->requestWithToken(null));
            self::fail('Expected ApiProblem was not thrown');
        } catch (ApiProblem $e) {
            self::assertSame(401, $e->getStatusCode());
        }
    }
}
