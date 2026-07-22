<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\AdminInvite;
use App\Entity\AdminUser;
use App\Entity\WebAuthnCredential;
use App\Enum\AdminRole;
use App\Enum\AdminUserStatus;
use App\Service\GeneratedInvite;
use App\Service\InviteTokenService;

final class RegistrationTest extends AdminTestCase
{
    /** @return array{0: AdminUser, 1: AdminInvite, 2: GeneratedInvite} */
    private function createInvite(\DateTimeImmutable $expiresAt): array
    {
        $user = new AdminUser('Neu', 'neu@example.com', AdminRole::Moderator);
        $this->em->persist($user);

        $gen = (new InviteTokenService())->generate();
        $invite = new AdminInvite($gen->selector, $gen->hash, $user, AdminRole::Moderator, $expiresAt);
        $this->em->persist($invite);
        $this->em->flush();

        return [$user, $invite, $gen];
    }

    public function testRegisterWithValidTokenActivatesAccount(): void
    {
        [$user, , $gen] = $this->createInvite(new \DateTimeImmutable('+72 hours'));

        $this->client->request('POST', '/api/admin/register', server: $this->hdr(),
            content: json_encode(['token' => $gen->token, 'attestation' => ['id' => 'cred-x']], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);

        $this->em->refresh($user);
        self::assertSame(AdminUserStatus::Active, $user->status);
    }

    public function testRegisterOptionsWithValidTokenReturnsCreationOptions(): void
    {
        [, , $gen] = $this->createInvite(new \DateTimeImmutable('+72 hours'));

        $this->client->request('POST', '/api/admin/register/options', server: $this->hdr(),
            content: json_encode(['token' => $gen->token], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(200);
        self::assertArrayHasKey('challenge', $this->json());
    }

    public function testRegisterWithExpiredTokenFails(): void
    {
        [, , $gen] = $this->createInvite(new \DateTimeImmutable('-1 hour'));

        $this->client->request('POST', '/api/admin/register', server: $this->hdr(),
            content: json_encode(['token' => $gen->token, 'attestation' => ['id' => 'cred-x']], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(400);
    }

    public function testRegisterWithUsedTokenFails(): void
    {
        [$user, $invite, $gen] = $this->createInvite(new \DateTimeImmutable('+72 hours'));
        $invite->usedAt = new \DateTimeImmutable();
        $this->em->flush();

        $this->client->request('POST', '/api/admin/register', server: $this->hdr(),
            content: json_encode(['token' => $gen->token, 'attestation' => ['id' => 'cred-x']], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(400);
    }

    public function testRegisterWithUnknownTokenFails(): void
    {
        $this->client->request('POST', '/api/admin/register', server: $this->hdr(),
            content: json_encode(['token' => 'gsta_0000000000000000_' . str_repeat('a', 43), 'attestation' => ['id' => 'cred-x']], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(404);
    }

    public function testRegisterWithMalformedTokenFails(): void
    {
        $this->client->request('POST', '/api/admin/register', server: $this->hdr(),
            content: json_encode(['token' => 'not-a-token', 'attestation' => ['id' => 'cred-x']], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(400);
    }

    public function testRegisterRejectedForDisabledAccount(): void
    {
        $user = $this->createAdmin('disabled@example.com', AdminRole::Moderator, AdminUserStatus::Disabled);

        $gen = (new InviteTokenService())->generate();
        $invite = new AdminInvite($gen->selector, $gen->hash, $user, AdminRole::Moderator, new \DateTimeImmutable('+72 hours'));
        $this->em->persist($invite);
        $this->em->flush();

        $this->client->request('POST', '/api/admin/register', server: $this->hdr(),
            content: json_encode(['token' => $gen->token, 'attestation' => ['id' => 'cred-x']], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(409);

        $this->em->refresh($user);
        self::assertSame(AdminUserStatus::Disabled, $user->status);
        self::assertCount(0, $user->credentials);
    }

    public function testRegisterOptionsRejectedForDisabledAccount(): void
    {
        $user = $this->createAdmin('disabled-options@example.com', AdminRole::Moderator, AdminUserStatus::Disabled);

        $gen = (new InviteTokenService())->generate();
        $invite = new AdminInvite($gen->selector, $gen->hash, $user, AdminRole::Moderator, new \DateTimeImmutable('+72 hours'));
        $this->em->persist($invite);
        $this->em->flush();

        $this->client->request('POST', '/api/admin/register/options', server: $this->hdr(),
            content: json_encode(['token' => $gen->token], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(409);
    }

    public function testRegisterRecoveryForActiveAccountAddsPasskey(): void
    {
        $user = $this->createAdmin('recovery@example.com', AdminRole::Moderator, AdminUserStatus::Active);
        $this->em->persist(new WebAuthnCredential($user, 'cred-1', '{"id":"x"}', 'Key 1'));
        $this->em->flush();

        $gen = (new InviteTokenService())->generate();
        $invite = new AdminInvite($gen->selector, $gen->hash, $user, AdminRole::Moderator, new \DateTimeImmutable('+72 hours'));
        $this->em->persist($invite);
        $this->em->flush();

        $this->client->request('POST', '/api/admin/register', server: $this->hdr(),
            content: json_encode(['token' => $gen->token, 'attestation' => ['id' => 'cred-2']], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);

        $this->em->refresh($user);
        self::assertSame(AdminUserStatus::Active, $user->status);
        self::assertCount(2, $user->credentials);
    }

    public function testRegisterInvalidatesSiblingInvites(): void
    {
        $user = new AdminUser('Neu', 'siblings@example.com', AdminRole::Moderator);
        $this->em->persist($user);

        $gen1 = (new InviteTokenService())->generate();
        $invite1 = new AdminInvite($gen1->selector, $gen1->hash, $user, AdminRole::Moderator, new \DateTimeImmutable('+72 hours'));
        $gen2 = (new InviteTokenService())->generate();
        $invite2 = new AdminInvite($gen2->selector, $gen2->hash, $user, AdminRole::Moderator, new \DateTimeImmutable('+72 hours'));
        $this->em->persist($invite1);
        $this->em->persist($invite2);
        $this->em->flush();

        $this->client->request('POST', '/api/admin/register', server: $this->hdr(),
            content: json_encode(['token' => $gen1->token, 'attestation' => ['id' => 'cred-x']], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);

        $this->client->request('POST', '/api/admin/register', server: $this->hdr(),
            content: json_encode(['token' => $gen2->token, 'attestation' => ['id' => 'cred-y']], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(400);
    }
}
