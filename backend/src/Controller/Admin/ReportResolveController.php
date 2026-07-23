<?php
declare(strict_types=1);
namespace App\Controller\Admin;

use App\Entity\AdminUser;
use App\Exception\ApiProblem;
use App\Repository\ReportRepository;
use App\Security\BackupPasskeyGate;
use App\Security\StepUpGuard;
use App\Service\AuditLogger;
use App\Service\ModerationService;
use App\Service\ScreenshotStorage;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ReportResolveController
{
    #[Route('/api/admin/reports/{id}/resolve', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function __invoke(int $id, Request $request, ReportRepository $reports, ModerationService $moderation, AuditLogger $audit, Security $security, StepUpGuard $stepUp, BackupPasskeyGate $backup, EntityManagerInterface $em, ScreenshotStorage $screenshots): Response
    {
        $report = $reports->find($id) ?? throw new ApiProblem(404, 'Report not found');

        try {
            $body = json_decode($request->getContent(), true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ApiProblem(400, 'Invalid JSON body');
        }
        if (!\is_bool($body['publish'] ?? null)) {
            throw new ApiProblem(400, '»publish« muss ein Boolean sein');
        }
        $publish = $body['publish'];

        /** @var AdminUser $actor */
        $actor = $security->getUser();

        // publish:false löscht den Eintrag (Soft-Delete) und entfernt den
        // Screenshot irreversibel – wie das Ablehnen eines Eintrags eine
        // destruktive Aktion und daher an beide Gates gebunden (frisches
        // Step-up UND mindestens zwei Passkeys). publish:true ist restaurativ.
        if (!$publish) {
            $backup->assertEnough($actor);
            $stepUp->assertFresh();
        }

        // Status-Guards innerhalb der Transaktion abfangen (nicht werfen –
        // ein Throw aus wrapInTransaction schlösse den EntityManager).
        $pathToDelete = null;
        $conflict = $em->wrapInTransaction(function () use ($moderation, $report, $publish, $audit, $actor, $em, &$pathToDelete): ?string {
            // Betroffenes Aggregat sperren und frisch lesen, damit paralleles
            // Approve/Reject/Resolve den Status-Guard nicht umgeht.
            $em->lock($report->entry, LockMode::PESSIMISTIC_WRITE);
            $em->refresh($report->entry);
            try {
                $pathToDelete = $moderation->resolveReport($report, $publish);
            } catch (\RuntimeException $e) {
                return $e->getMessage();
            }
            $audit->log($actor, 'report.resolve', 'report', (string) $report->id, ['publish' => $publish]);

            return null;
        });

        if ($conflict !== null) {
            throw new ApiProblem(409, $conflict);
        }

        // Datei erst nach erfolgreichem Commit löschen.
        $screenshots->deleteFileAt($pathToDelete);

        return new Response('', 204);
    }
}
