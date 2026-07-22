<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\AdminInvite;
use App\Entity\AdminUser;
use App\Enum\AdminRole;
use App\Exception\ApiProblem;
use App\Repository\AdminUserRepository;
use App\Security\BackupPasskeyGate;
use App\Security\StepUpGuard;
use App\Service\AuditLogger;
use App\Service\InviteMailer;
use App\Service\InviteTokenService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Lädt einen neuen Admin-Nutzer ein: legt den Account im Status `invited`
 * an, erzeugt ein 72h-Invite-Token und verschickt die Einladungs-E-Mail.
 * Nur `admin` (access_control), zusätzlich Step-up + Backup-Passkey-Gate,
 * da hiermit neue privilegierte Accounts entstehen.
 */
final class UserInviteController
{
    #[Route('/api/admin/users', methods: ['POST'])]
    public function __invoke(
        Request $request,
        Security $security,
        AdminUserRepository $users,
        EntityManagerInterface $em,
        InviteTokenService $tokens,
        InviteMailer $mailer,
        AuditLogger $audit,
        StepUpGuard $stepUp,
        BackupPasskeyGate $backup,
    ): JsonResponse {
        /** @var AdminUser $actor */
        $actor = $security->getUser();
        $backup->assertEnough($actor);
        $stepUp->assertFresh();

        try {
            $body = json_decode($request->getContent(), true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ApiProblem(400, 'Invalid JSON body');
        }

        $email = $body['email'] ?? null;
        $displayName = $body['displayName'] ?? null;
        $role = AdminRole::tryFrom((string) ($body['role'] ?? '')) ?? throw new ApiProblem(400, 'Invalid role');

        if (!is_string($email) || !is_string($displayName) || '' === $email || '' === $displayName) {
            throw new ApiProblem(400, 'displayName and email are required');
        }
        if (null !== $users->findOneByEmail($email)) {
            throw new ApiProblem(409, 'Email already exists');
        }

        $user = new AdminUser($displayName, $email, $role);
        $em->persist($user);

        $gen = $tokens->generate();
        $expiresAt = new \DateTimeImmutable('+72 hours');
        $invite = new AdminInvite($gen->selector, $gen->hash, $user, $role, $expiresAt);
        $invite->createdBy = $actor;
        $em->persist($invite);
        $em->flush();

        $mailer->send($email, $gen->token, $expiresAt);
        $audit->log($actor, 'user.invite', 'admin_user', (string) $user->id, ['role' => $role->value]);

        return new JsonResponse(['id' => $user->id, 'email' => $user->email, 'status' => $user->status->value], 201);
    }
}
