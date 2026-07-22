<?php
declare(strict_types=1);
namespace App\Controller\Admin;

use App\Entity\AdminUser;
use App\Exception\ApiProblem;
use App\Repository\SubmitterRepository;
use App\Service\AuditLogger;
use App\Service\ModerationService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class SubmitterUnbanController
{
    #[Route('/api/admin/submitters/{id}/unban', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function __invoke(int $id, SubmitterRepository $submitters, ModerationService $moderation, AuditLogger $audit, Security $security): Response
    {
        $submitter = $submitters->find($id) ?? throw new ApiProblem(404, 'Submitter not found');

        try {
            $moderation->unban($submitter);
        } catch (\RuntimeException $e) {
            throw new ApiProblem(409, $e->getMessage());
        }

        /** @var AdminUser $actor */
        $actor = $security->getUser();
        $audit->log($actor, 'submitter.unban', 'submitter', (string) $submitter->id);

        return new Response('', 204);
    }
}
