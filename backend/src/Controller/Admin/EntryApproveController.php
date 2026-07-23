<?php
declare(strict_types=1);
namespace App\Controller\Admin;

use App\Entity\AdminUser;
use App\Exception\ApiProblem;
use App\Repository\EntryRepository;
use App\Service\AuditLogger;
use App\Service\ModerationService;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class EntryApproveController
{
    #[Route('/api/admin/entries/{id}/approve', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function __invoke(int $id, EntryRepository $entries, ModerationService $moderation, AuditLogger $audit, Security $security, EntityManagerInterface $em): Response
    {
        $entry = $entries->find($id) ?? throw new ApiProblem(404, 'Entry not found');

        /** @var AdminUser $actor */
        $actor = $security->getUser();

        // Status-Guard innerhalb der Transaktion abfangen (nicht werfen –
        // ein Throw aus wrapInTransaction schlösse den EntityManager).
        $conflict = $em->wrapInTransaction(function () use ($moderation, $entry, $audit, $actor, $em): ?string {
            // Aggregat sperren und frisch lesen: serialisiert paralleles
            // Approve/Reject, sodass der Status-Guard konsistent greift.
            $em->lock($entry, LockMode::PESSIMISTIC_WRITE);
            $em->refresh($entry);
            try {
                $moderation->approveEntry($entry);
            } catch (\RuntimeException $e) {
                return $e->getMessage();
            }
            $audit->log($actor, 'entry.approve', 'entry', (string) $entry->id);

            return null;
        });

        if ($conflict !== null) {
            throw new ApiProblem(409, $conflict);
        }

        return new Response('', 204);
    }
}
