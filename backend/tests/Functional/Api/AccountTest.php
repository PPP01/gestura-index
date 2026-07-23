<?php
declare(strict_types=1);
namespace App\Tests\Functional\Api;

use App\Entity\Account;
use App\Tests\Functional\ApiTestCase;

final class AccountTest extends ApiTestCase
{
    /** @return string Klartext-Token */
    protected function createAccount(): string
    {
        $this->client->request('POST', '/api/account');
        self::assertResponseStatusCodeSame(201);

        return $this->json()['token'];
    }

    public function testCreateReturnsTokenAndPersistsAccount(): void
    {
        $token = $this->createAccount();
        self::assertMatchesRegularExpression('/^gacc_[0-9a-f]{16}_[A-Za-z0-9_-]{43}$/', $token);
        self::assertSame(1, $this->em->getRepository(Account::class)->count([]));
    }

    public function testMeWithValidTokenReturnsCreatedAt(): void
    {
        $token = $this->createAccount();
        $this->client->request('GET', '/api/account/me', server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        self::assertResponseStatusCodeSame(200);
        self::assertArrayHasKey('createdAt', $this->json());
    }

    public function testMeWithoutTokenIs401(): void
    {
        $this->client->request('GET', '/api/account/me');
        self::assertResponseStatusCodeSame(401);
    }

    public function testMeWithInvalidTokenIs401(): void
    {
        $bogus = 'gacc_' . str_repeat('a', 16) . '_' . str_repeat('b', 43);
        $this->client->request('GET', '/api/account/me', server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $bogus]);
        self::assertResponseStatusCodeSame(401);
    }

    public function testDeleteRemovesAccountAndInvalidatesToken(): void
    {
        $token = $this->createAccount();
        $this->client->request('DELETE', '/api/account', server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        self::assertResponseStatusCodeSame(204);

        $this->client->request('GET', '/api/account/me', server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        self::assertResponseStatusCodeSame(401);
    }
}
