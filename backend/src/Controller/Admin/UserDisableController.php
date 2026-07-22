<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\AdminUser;
use App\Enum\AdminRole;
use App\Enum\AdminUserStatus;
use App\Exception\ApiProblem;
use App\Repository\AdminUserRepository;
use App\Security\StepUpGuard;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Sperrt einen Admin-Nutzer (Status `disabled`) – destruktiv genug für
 * Step-up, aber kein Backup-Passkey-Gate (kein zweiter eigener Passkey
 * des Ziel-Accounts nötig). Verweigert das Selbst-Aussperren und das
 * Deaktivieren des letzten aktiven Admins (sonst gäbe es keinen
 * ROLE_ADMIN-Account mehr, der reaktivieren könnte).
 */
final class UserDisableController
{
    #[Route('/api/admin/users/{id}/disable', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function __invoke(
        int $id,
        AdminUserRepository $users,
        EntityManagerInterface $em,
        AuditLogger $audit,
        Security $security,
        StepUpGuard $stepUp,
    ): Response {
        /** @var AdminUser $actor */
        $actor = $security->getUser();
        $stepUp->assertFresh();

        $user = $users->find($id) ?? throw new ApiProblem(404, 'User not found');

        if ($id === $actor->id) {
            throw new ApiProblem(409, 'Cannot disable your own account');
        }

        if (AdminRole::Admin === $user->role && AdminUserStatus::Active === $user->status && 1 === $users->countActiveAdmins()) {
            throw new ApiProblem(409, 'Cannot disable the last active admin');
        }

        $user->status = AdminUserStatus::Disabled;
        $em->flush();

        $audit->log($actor, 'user.disable', 'admin_user', (string) $user->id);

        return new Response('', 204);
    }
}
