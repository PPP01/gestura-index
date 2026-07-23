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
}
