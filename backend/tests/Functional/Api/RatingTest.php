<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\Account;
use App\Entity\Rating;
use App\Entity\Submitter;
use App\Repository\SubmitterRepository;
use App\Tests\Functional\ApiTestCase;

final class RatingTest extends ApiTestCase
{
    /** @return string Klartext-Konto-Token */
    private function createAccount(): string
    {
        $this->client->request('POST', '/api/account');
        self::assertResponseStatusCodeSame(201);

        return $this->json()['token'];
    }

    /** @return array<string, string> */
    private function hdr(string $token): array
    {
        return ['HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'CONTENT_TYPE' => 'application/json'];
    }

    private function accountFor(string $token): Account
    {
        return $this->em->getRepository(Account::class)->findOneBy(['tokenSelector' => explode('_', $token)[1]]);
    }

    private function putRating(string $token, string $formatId, array $body): void
    {
        $this->client->request('PUT', '/api/v1/entries/' . $formatId . '/rating',
            server: $this->hdr($token), content: json_encode($body, JSON_THROW_ON_ERROR));
    }

    public function testUpsertUpdateAndDelete(): void
    {
        $this->createPublishedEntry('com.example.r1');
        $token = $this->createAccount();

        $this->putRating($token, 'com.example.r1', ['stars' => 4]);
        self::assertResponseStatusCodeSame(200);
        self::assertSame(4, $this->json()['stars']);

        // Zweites PUT aktualisiert dieselbe Bewertung (kein zweiter Datensatz):
        $this->putRating($token, 'com.example.r1', ['stars' => 2]);
        self::assertResponseStatusCodeSame(200);
        self::assertSame(2, $this->json()['stars']);
        self::assertSame(1, $this->em->getRepository(Rating::class)->count([]));

        $this->client->request('GET', '/api/v1/entries/com.example.r1/rating', server: $this->hdr($token));
        self::assertResponseStatusCodeSame(200);
        self::assertSame(2, $this->json()['stars']);

        $this->client->request('DELETE', '/api/v1/entries/com.example.r1/rating', server: $this->hdr($token));
        self::assertResponseStatusCodeSame(204);
        $this->client->request('GET', '/api/v1/entries/com.example.r1/rating', server: $this->hdr($token));
        self::assertResponseStatusCodeSame(404);
    }

    public function testCommentFromNewAccountIsPendingButStarCounts(): void
    {
        $this->createPublishedEntry('com.example.r2');
        $token = $this->createAccount();

        $this->putRating($token, 'com.example.r2', ['stars' => 5, 'comment' => 'klasse']);
        self::assertResponseStatusCodeSame(200);
        // Neues Konto (kein approvedCount) → Kommentar wartet:
        self::assertSame('pending', $this->json()['commentStatus']);
        self::assertSame(5, $this->json()['stars']);
    }

    public function testCommentFromTrustedAccountIsApproved(): void
    {
        $entry = $this->createPublishedEntry('com.example.r3');
        $token = $this->createAccount();
        $account = $this->accountFor($token);

        // Vertrauen aufbauen: ein verknüpfter Submitter mit approvedCount ≥ TRUST_THRESHOLD (3).
        [$submitter] = $this->createSubmitterWithToken();
        $submitter->account = $account;
        $submitter->approvedCount = 3;
        $this->em->flush();

        $this->putRating($token, 'com.example.r3', ['stars' => 5, 'comment' => 'vertraut']);
        self::assertResponseStatusCodeSame(200);
        self::assertSame('approved', $this->json()['commentStatus']);
    }

    public function testCannotRateOwnEntry(): void
    {
        $token = $this->createAccount();
        $account = $this->accountFor($token);
        [$submitter] = $this->createSubmitterWithToken();
        $submitter->account = $account;
        $this->em->flush();
        $this->createPublishedEntry('com.example.mine', [], $submitter);

        $this->putRating($token, 'com.example.mine', ['stars' => 5]);
        self::assertResponseStatusCodeSame(403);
    }

    public function testBannedBundleCannotRate(): void
    {
        $this->createPublishedEntry('com.example.r4');
        $token = $this->createAccount();
        $account = $this->accountFor($token);
        [$submitter] = $this->createSubmitterWithToken();
        $submitter->account = $account;
        $submitter->banned = true;
        $this->em->flush();

        $this->putRating($token, 'com.example.r4', ['stars' => 5]);
        self::assertResponseStatusCodeSame(403);
    }

    public function testUnpublishedEntryIs404(): void
    {
        $token = $this->createAccount();
        $this->putRating($token, 'com.example.nope', ['stars' => 5]);
        self::assertResponseStatusCodeSame(404);
    }

    public function testInvalidStarsAndComment(): void
    {
        $this->createPublishedEntry('com.example.r5');
        $token = $this->createAccount();

        $this->putRating($token, 'com.example.r5', ['stars' => 6]);
        self::assertResponseStatusCodeSame(400);
        $this->putRating($token, 'com.example.r5', ['stars' => 0]);
        self::assertResponseStatusCodeSame(400);
        $this->putRating($token, 'com.example.r5', ['stars' => 'x']);
        self::assertResponseStatusCodeSame(400);
        $this->putRating($token, 'com.example.r5', ['stars' => 3, 'comment' => str_repeat('x', 501)]);
        self::assertResponseStatusCodeSame(400);
    }

    public function testWithoutTokenIs401(): void
    {
        $this->createPublishedEntry('com.example.r6');
        $this->client->request('PUT', '/api/v1/entries/com.example.r6/rating',
            server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['stars' => 5], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(401);
    }

    public function testGetWithoutTokenIs401(): void
    {
        $this->createPublishedEntry('com.example.r7');
        $this->client->request('GET', '/api/v1/entries/com.example.r7/rating');
        self::assertResponseStatusCodeSame(401);
    }

    public function testDeleteWithoutTokenIs401(): void
    {
        $this->createPublishedEntry('com.example.r8');
        $this->client->request('DELETE', '/api/v1/entries/com.example.r8/rating');
        self::assertResponseStatusCodeSame(401);
    }
}
