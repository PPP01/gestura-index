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
 * Reaktiviert einen zuvor gesperrten Admin-Nutzer. Bewusst der einzige
 * Reaktivierungs-Pfad – niemals über einen liegen gebliebenen Invite-Token
 * (siehe UserReinviteController), damit eine Sperre nicht durch ein altes,
 * noch gültiges Token unterlaufen werden kann. Destruktiv genug für Step-up,
 * kein Backup-Passkey-Gate nötig (kein zweiter eigener Passkey des Ziel-
 * Accounts erforderlich).
 *
 * Der Zielstatus richtet sich danach, ob sich der Nutzer je selbst registriert
 * hat: `disabled` überschreibt beim Sperren den Vorzustand (invited ODER
 * active), merkt ihn aber nicht. Rekonstruiert wird er über die Passkey-Zahl –
 * ein nie registrierter Nutzer hat keinen Passkey (die Erst-Registrierung legt
 * den ersten an und flippt auf `active`). Ohne Passkey also zurück auf
 * `invited` (er muss sich noch registrieren), sonst `active`.
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

        $user->status = 0 === $user->credentialCount()
            ? AdminUserStatus::Invited
            : AdminUserStatus::Active;
        $em->flush();

        $audit->log($actor, 'user.enable', 'admin_user', (string) $user->id);

        return new JsonResponse(['id' => $user->id, 'email' => $user->email, 'status' => $user->status->value]);
    }
}
