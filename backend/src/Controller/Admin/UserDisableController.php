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
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Sperrt einen Admin-Nutzer (Status `disabled`) – destruktiv genug für
 * Step-up, aber kein Backup-Passkey-Gate (kein zweiter eigener Passkey
 * des Ziel-Accounts nötig).
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
        $user->status = AdminUserStatus::Disabled;
        $em->flush();

        $audit->log($actor, 'user.disable', 'admin_user', (string) $user->id);

        return new Response('', 204);
    }
}
