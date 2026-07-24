<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\Account;
use App\Entity\Submitter;
use App\Service\EditTokenService;
use App\Tests\Functional\ApiTestCase;

final class AccountOwnershipTest extends ApiTestCase
{
    /** @return string Klartext-Konto-Token */
    private function createAccount(): string
    {
        $this->client->request('POST', '/api/account');
        self::assertResponseStatusCodeSame(201);

        return $this->json()['token'];
    }

    private function linkSubmitterToAccountToken(Submitter $submitter, string $accountToken): Account
    {
        // Konto über den Selector aus dem Token laden und verknüpfen:
        [, $selector] = explode('_', $accountToken, 3);
        $account = $this->em->getRepository(Account::class)->findOneBy(['tokenSelector' => $selector]);
        self::assertNotNull($account);
        $submitter->account = $account;
        $this->em->flush();

        return $account;
    }

    public function testAccountCanDeleteLinkedEntry(): void
    {
        $accountToken = $this->createAccount();
        [$submitter, ] = $this->createSubmitterWithToken();
        $entry = $this->createPublishedEntry('com.example.account-owns', submitter: $submitter);
        $this->linkSubmitterToAccountToken($entry->submitter, $accountToken);

        $this->client->request('DELETE', '/api/v1/entries/' . $entry->formatId,
            server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $accountToken]);
        self::assertResponseStatusCodeSame(204);
    }

    public function testAccountCannotManageForeignEntry(): void
    {
        $accountToken = $this->createAccount();
        $entry = $this->createPublishedEntry('com.example.account-foreign'); // Submitter NICHT verknüpft

        $this->client->request('DELETE', '/api/v1/entries/' . $entry->formatId,
            server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $accountToken]);
        self::assertResponseStatusCodeSame(403);
    }

    public function testBannedBundleBlocksAccountManagement(): void
    {
        $accountToken = $this->createAccount();
        [$submitter, ] = $this->createSubmitterWithToken();
        $entry = $this->createPublishedEntry('com.example.account-banned-bundle', submitter: $submitter);
        $account = $this->linkSubmitterToAccountToken($entry->submitter, $accountToken);

        // Zweiter, gesperrter Submitter im selben Konto-Bündel:
        $generated = (new EditTokenService())->generate();
        $banned = new Submitter($generated->selector, $generated->hash);
        $banned->banned = true;
        $banned->account = $account;
        $this->em->persist($banned);
        $this->em->flush();

        $this->client->request('DELETE', '/api/v1/entries/' . $entry->formatId,
            server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $accountToken]);
        self::assertResponseStatusCodeSame(403);
    }

    public function testClassicEditTokenPathStillWorks(): void
    {
        [$submitter, $editToken] = $this->createSubmitterWithToken();
        $entry = $this->createPublishedEntry('com.example.account-classic', submitter: $submitter);

        $this->client->request('DELETE', '/api/v1/entries/' . $entry->formatId,
            server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $editToken]);
        self::assertResponseStatusCodeSame(204);
    }
}
