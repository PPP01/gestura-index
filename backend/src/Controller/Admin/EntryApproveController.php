<?php
declare(strict_types=1);
namespace App\Controller\Admin;

use App\Entity\AdminUser;
use App\Exception\ApiProblem;
use App\Repository\EntryRepository;
use App\Service\AuditLogger;
use App\Service\ModerationService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class EntryApproveController
{
    #[Route('/api/admin/entries/{id}/approve', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function __invoke(int $id, EntryRepository $entries, ModerationService $moderation, AuditLogger $audit, Security $security): Response
    {
        $entry = $entries->find($id) ?? throw new ApiProblem(404, 'Entry not found');

        try {
            $moderation->approveEntry($entry);
        } catch (\RuntimeException $e) {
            throw new ApiProblem(409, $e->getMessage());
        }

        /** @var AdminUser $actor */
        $actor = $security->getUser();
        $audit->log($actor, 'entry.approve', 'entry', (string) $entry->id);

        return new Response('', 204);
    }
}
