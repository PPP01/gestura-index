<?php
declare(strict_types=1);
namespace App\Controller\Admin;

use App\Entity\AdminUser;
use App\Exception\ApiProblem;
use App\Repository\SubmitterRepository;
use App\Security\BackupPasskeyGate;
use App\Security\StepUpGuard;
use App\Service\AuditLogger;
use App\Service\ModerationService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class SubmitterBanController
{
    #[Route('/api/admin/submitters/{id}/ban', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function __invoke(
        int $id,
        SubmitterRepository $submitters,
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

        $submitter = $submitters->find($id) ?? throw new ApiProblem(404, 'Submitter not found');

        $moderation->ban($submitter);

        $audit->log($actor, 'submitter.ban', 'submitter', (string) $submitter->id);

        return new Response('', 204);
    }
}
