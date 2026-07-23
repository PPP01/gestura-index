<?php
declare(strict_types=1);
namespace App\Controller\Admin;

use App\Entity\AdminUser;
use App\Exception\ApiProblem;
use App\Repository\EntryVersionRepository;
use App\Security\BackupPasskeyGate;
use App\Security\StepUpGuard;
use App\Service\AuditLogger;
use App\Service\ModerationService;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class VersionRejectController
{
    #[Route('/api/admin/versions/{id}/reject', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function __invoke(
        int $id,
        EntryVersionRepository $versions,
        ModerationService $moderation,
        AuditLogger $audit,
        Security $security,
        StepUpGuard $stepUp,
        BackupPasskeyGate $backup,
        EntityManagerInterface $em,
    ): Response {
        /** @var AdminUser $actor */
        $actor = $security->getUser();
        $backup->assertEnough($actor);
        $stepUp->assertFresh();

        $version = $versions->find($id) ?? throw new ApiProblem(404, 'Version not found');

        // Status-Guard innerhalb der Transaktion abfangen (nicht werfen –
        // ein Throw aus wrapInTransaction schlösse den EntityManager).
        $conflict = $em->wrapInTransaction(function () use ($moderation, $version, $audit, $actor, $em): ?string {
            // Aggregat (Entry) sperren und Version frisch lesen: serialisiert
            // paralleles Approve/Reject auf derselben Version.
            $em->lock($version->entry, LockMode::PESSIMISTIC_WRITE);
            $em->refresh($version);
            try {
                $moderation->rejectVersion($version);
            } catch (\RuntimeException $e) {
                return $e->getMessage();
            }
            $audit->log($actor, 'version.reject', 'version', (string) $version->id);

            return null;
        });

        if ($conflict !== null) {
            throw new ApiProblem(409, $conflict);
        }

        return new Response('', 204);
    }
}
