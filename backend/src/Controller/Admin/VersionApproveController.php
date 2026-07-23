<?php
declare(strict_types=1);
namespace App\Controller\Admin;

use App\Entity\AdminUser;
use App\Exception\ApiProblem;
use App\Repository\EntryVersionRepository;
use App\Service\AuditLogger;
use App\Service\ModerationService;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class VersionApproveController
{
    #[Route('/api/admin/versions/{id}/approve', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function __invoke(int $id, EntryVersionRepository $versions, ModerationService $moderation, AuditLogger $audit, Security $security, EntityManagerInterface $em): Response
    {
        $version = $versions->find($id) ?? throw new ApiProblem(404, 'Version not found');

        /** @var AdminUser $actor */
        $actor = $security->getUser();

        // Mutation + Audit atomar (ein Commit am Tx-Ende). Der Status-Guard
        // wird INNERHALB der Closure abgefangen und als Konfliktmeldung
        // zurückgegeben statt geworfen – ein Throw aus wrapInTransaction würde
        // den EntityManager schließen. Der 409 fällt nach dem Commit.
        $conflict = $em->wrapInTransaction(function () use ($moderation, $version, $audit, $actor, $em): ?string {
            // Aggregat (Entry) sperren und Version frisch lesen: serialisiert
            // paralleles Approve/Reject, sodass beide Guards nicht gleichzeitig
            // »Pending« sehen und currentVersion inkonsistent wird.
            $em->lock($version->entry, LockMode::PESSIMISTIC_WRITE);
            $em->refresh($version);
            try {
                $moderation->approveVersion($version);
            } catch (\RuntimeException $e) {
                return $e->getMessage();
            }
            $audit->log($actor, 'version.approve', 'version', (string) $version->id);

            return null;
        });

        if ($conflict !== null) {
            throw new ApiProblem(409, $conflict);
        }

        return new Response('', 204);
    }
}
