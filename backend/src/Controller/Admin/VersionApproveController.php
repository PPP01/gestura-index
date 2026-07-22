<?php
declare(strict_types=1);
namespace App\Controller\Admin;

use App\Entity\AdminUser;
use App\Exception\ApiProblem;
use App\Repository\EntryVersionRepository;
use App\Service\AuditLogger;
use App\Service\ModerationService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class VersionApproveController
{
    #[Route('/api/admin/versions/{id}/approve', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function __invoke(int $id, EntryVersionRepository $versions, ModerationService $moderation, AuditLogger $audit, Security $security): Response
    {
        $version = $versions->find($id) ?? throw new ApiProblem(404, 'Version not found');

        try {
            $moderation->approveVersion($version);
        } catch (\RuntimeException $e) {
            throw new ApiProblem(409, $e->getMessage());
        }

        /** @var AdminUser $actor */
        $actor = $security->getUser();
        $audit->log($actor, 'version.approve', 'version', (string) $version->id);

        return new Response('', 204);
    }
}
