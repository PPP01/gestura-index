<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\AdminUser;
use App\Enum\AdminRole;
use App\Enum\AdminUserStatus;
use App\Exception\ApiProblem;
use App\Repository\AdminUserRepository;
use App\Security\BackupPasskeyGate;
use App\Security\StepUpGuard;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Sperrt einen Admin-Nutzer (Status `disabled`). Destruktiv, daher – wie die
 * übrigen Nutzerverwaltungs-Aktionen – mit frischem Step-up UND Backup-Passkey-
 * Gate (mindestens zwei registrierte Passkeys des handelnden Admins) abgesichert.
 * Verweigert das Selbst-Aussperren und das Deaktivieren des letzten aktiven
 * Admins (sonst gäbe es keinen ROLE_ADMIN-Account mehr, der reaktivieren könnte).
 * Der »letzter Admin«-Check läuft unter einer FOR-UPDATE-Sperre, damit zwei
 * gleichzeitige Disables ihn nicht per Race unterlaufen.
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
        BackupPasskeyGate $backup,
    ): Response {
        /** @var AdminUser $actor */
        $actor = $security->getUser();
        $backup->assertEnough($actor);
        $stepUp->assertFresh();

        $user = $users->find($id) ?? throw new ApiProblem(404, 'User not found');

        if ($id === $actor->id) {
            throw new ApiProblem(409, 'Cannot disable your own account');
        }

        // Konflikt-Guards INNERHALB der Transaktion abfangen und als Meldung
        // zurückgeben statt werfen – ein Throw aus wrapInTransaction schlösse
        // den EntityManager. Auf dem Ablehnungs-Pfad wird nichts mutiert, der
        // (leere) Commit gibt die FOR-UPDATE-Sperre wieder frei.
        $conflict = $em->wrapInTransaction(function () use ($users, $user, $em, $audit, $actor): ?string {
            // Aktive Admins sperren, dann den Zielnutzer unter der Sperre neu
            // lesen – so basieren Zähler und Statuscheck auf konsistentem Stand.
            $users->lockActiveAdmins();
            $em->refresh($user);

            if (AdminUserStatus::Disabled === $user->status) {
                return 'User is already disabled';
            }

            if (AdminRole::Admin === $user->role && AdminUserStatus::Active === $user->status && 1 === $users->countActiveAdmins()) {
                return 'Cannot disable the last active admin';
            }

            $user->status = AdminUserStatus::Disabled;
            $audit->log($actor, 'user.disable', 'admin_user', (string) $user->id);

            return null;
        });

        if ($conflict !== null) {
            throw new ApiProblem(409, $conflict);
        }

        return new Response('', 204);
    }
}
