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
    ): Response {
        /** @var AdminUser $actor */
        $actor = $security->getUser();
        $backup->assertEnough($actor);
        $stepUp->assertFresh();

        $version = $versions->find($id) ?? throw new ApiProblem(404, 'Version not found');

        $moderation->rejectVersion($version);

        $audit->log($actor, 'version.reject', 'version', (string) $version->id);

        return new Response('', 204);
    }
}
