<?php
declare(strict_types=1);
namespace App\Controller\Admin;

use App\Entity\AdminUser;
use App\Enum\AdminUserStatus;
use App\Exception\ApiProblem;
use App\Repository\AdminUserRepository;
use App\Security\StepUpGuard;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Reaktiviert einen zuvor gesperrten Admin-Nutzer (Status `disabled` →
 * `active`). Bewusst der einzige Reaktivierungs-Pfad – niemals über einen
 * liegen gebliebenen Invite-Token (siehe UserReinviteController), damit
 * eine Sperre nicht durch ein altes, noch gültiges Token unterlaufen
 * werden kann. Destruktiv genug für Step-up, kein Backup-Passkey-Gate
 * nötig (kein zweiter eigener Passkey des Ziel-Accounts erforderlich).
 */
final class UserEnableController
{
    #[Route('/api/admin/users/{id}/enable', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function __invoke(
        int $id,
        AdminUserRepository $users,
        EntityManagerInterface $em,
        AuditLogger $audit,
        Security $security,
        StepUpGuard $stepUp,
    ): JsonResponse {
        /** @var AdminUser $actor */
        $actor = $security->getUser();
        $stepUp->assertFresh();

        $user = $users->find($id) ?? throw new ApiProblem(404, 'User not found');

        if (AdminUserStatus::Disabled !== $user->status) {
            throw new ApiProblem(409, 'User is not disabled');
        }

        $user->status = AdminUserStatus::Active;
        $em->flush();

        $audit->log($actor, 'user.enable', 'admin_user', (string) $user->id);

        return new JsonResponse(['id' => $user->id, 'email' => $user->email, 'status' => $user->status->value]);
    }
}
