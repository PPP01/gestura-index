<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\AdminInvite;
use App\Entity\AdminUser;
use App\Enum\AdminUserStatus;
use App\Exception\ApiProblem;
use App\Repository\AdminInviteRepository;
use App\Repository\AdminUserRepository;
use App\Security\BackupPasskeyGate;
use App\Security\StepUpGuard;
use App\Service\AuditLogger;
use App\Service\InviteMailer;
use App\Service\InviteTokenService;
use App\Service\RateLimitGuard;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Erzeugt ein neues 72h-Invite-Token für einen bestehenden Admin-Nutzer
 * (z. B. weil das erste Invite ungenutzt abgelaufen ist, oder als Recovery-
 * Pfad für einen Active-Nutzer, der seinen Passkey verloren hat) und
 * verschickt erneut die Einladungs-E-Mail. Wie UserInviteController eine
 * sensible Provisionierungsaktion – daher Step-up + Backup-Passkey-Gate.
 */
final class UserReinviteController
{
    #[Route('/api/admin/users/{id}/reinvite', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function __invoke(
        int $id,
        Request $request,
        AdminUserRepository $users,
        AdminInviteRepository $invites,
        EntityManagerInterface $em,
        InviteTokenService $tokens,
        InviteMailer $mailer,
        AuditLogger $audit,
        Security $security,
        BackupPasskeyGate $backup,
        StepUpGuard $stepUp,
        RateLimitGuard $guard,
        RateLimiterFactoryInterface $adminInviteLimiter,
    ): JsonResponse {
        /** @var AdminUser $actor */
        $actor = $security->getUser();
        $backup->assertEnough($actor);
        $stepUp->assertFresh();
        $guard->consume($adminInviteLimiter, $request->getClientIp() ?? 'unknown');

        $user = $users->find($id) ?? throw new ApiProblem(404, 'User not found');

        // Ein deaktivierter Account darf nur bewusst über /enable
        // reaktiviert werden, nie über einen erneuten Invite-Link.
        if (AdminUserStatus::Disabled === $user->status) {
            throw new ApiProblem(409, 'Cannot reinvite a disabled user');
        }

        // Alte, noch gültige Invites desselben Nutzers invalidieren, damit
        // nur das neu verschickte Token verwendbar ist (Replay-Schutz).
        foreach ($invites->findUnusedForUser($user) as $stale) {
            $stale->usedAt = new \DateTimeImmutable();
        }

        $gen = $tokens->generate();
        $expiresAt = new \DateTimeImmutable('+72 hours');
        $invite = new AdminInvite($gen->selector, $gen->hash, $user, $user->role, $expiresAt);
        $invite->createdBy = $actor;
        $em->persist($invite);
        $em->flush();

        $mailer->send($user->email, $gen->token, $expiresAt);
        $audit->log($actor, 'user.reinvite', 'admin_user', (string) $user->id);

        return new JsonResponse(['id' => $user->id, 'email' => $user->email, 'status' => $user->status->value], 201);
    }
}
