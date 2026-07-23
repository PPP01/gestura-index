<?php
declare(strict_types=1);
namespace App\Controller\Admin;

use App\Entity\AdminUser;
use App\Exception\ApiProblem;
use App\Repository\EntryRepository;
use App\Security\BackupPasskeyGate;
use App\Security\StepUpGuard;
use App\Service\AuditLogger;
use App\Service\ModerationService;
use App\Service\ScreenshotStorage;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class EntryRejectController
{
    #[Route('/api/admin/entries/{id}/reject', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function __invoke(
        int $id,
        EntryRepository $entries,
        ModerationService $moderation,
        AuditLogger $audit,
        Security $security,
        StepUpGuard $stepUp,
        BackupPasskeyGate $backup,
        EntityManagerInterface $em,
        ScreenshotStorage $screenshots,
    ): Response {
        /** @var AdminUser $actor */
        $actor = $security->getUser();
        $backup->assertEnough($actor);
        $stepUp->assertFresh();

        $entry = $entries->find($id) ?? throw new ApiProblem(404, 'Entry not found');

        // Status-Guard innerhalb der Transaktion abfangen (nicht werfen –
        // ein Throw aus wrapInTransaction schlösse den EntityManager).
        $pathToDelete = null;
        $conflict = $em->wrapInTransaction(function () use ($moderation, $entry, $audit, $actor, $em, &$pathToDelete): ?string {
            // Aggregat sperren und frisch lesen: serialisiert paralleles
            // Approve/Reject auf demselben Eintrag, sodass der Status-Guard
            // nicht durch einen zwischenzeitlichen Commit umgangen wird.
            $em->lock($entry, LockMode::PESSIMISTIC_WRITE);
            $em->refresh($entry);
            try {
                $pathToDelete = $moderation->rejectEntry($entry);
            } catch (\RuntimeException $e) {
                return $e->getMessage();
            }
            $audit->log($actor, 'entry.reject', 'entry', (string) $entry->id);

            return null;
        });

        if ($conflict !== null) {
            throw new ApiProblem(409, $conflict);
        }

        // Datei erst nach erfolgreichem Commit löschen (bei Rollback bliebe sie
        // sonst verschwunden, während die DB-Referenz zurückgerollt wäre).
        $screenshots->deleteFileAt($pathToDelete);

        return new Response('', 204);
    }
}
