<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\AdminUser;
use App\Exception\ApiProblem;
use App\Repository\RatingRepository;
use App\Service\AuditLogger;
use App\Service\ModerationService;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Gibt einen wartenden Bewertungs-Kommentar frei. Kein Step-up (niedrig-
 * geschützt wie VersionApprove). Mutation + Audit atomar; der Status-Guard aus
 * ModerationService wird als Sentinel abgefangen und erst NACH dem Commit als
 * 409 geworfen (ein Throw aus wrapInTransaction schlösse den EntityManager).
 */
final class CommentApproveController
{
    #[Route('/api/admin/comments/{id}/approve', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function __invoke(
        int $id,
        RatingRepository $ratings,
        ModerationService $moderation,
        AuditLogger $audit,
        Security $security,
        EntityManagerInterface $em,
    ): Response {
        $rating = $ratings->find($id) ?? throw new ApiProblem(404, 'Rating not found');

        /** @var AdminUser $actor */
        $actor = $security->getUser();

        $conflict = $em->wrapInTransaction(function () use ($moderation, $rating, $audit, $actor, $em): ?string {
            // Die Rating-Zeile ist das Aggregat: serialisiert paralleles
            // Approve/Reject auf demselben Kommentar.
            $em->lock($rating, LockMode::PESSIMISTIC_WRITE);
            $em->refresh($rating);
            try {
                $moderation->approveComment($rating);
            } catch (\RuntimeException $e) {
                return $e->getMessage();
            }
            $audit->log($actor, 'comment.approve', 'comment', (string) $rating->id);

            return null;
        });

        if ($conflict !== null) {
            throw new ApiProblem(409, $conflict);
        }

        return new Response('', 204);
    }
}
