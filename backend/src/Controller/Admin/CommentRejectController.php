<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\AdminUser;
use App\Exception\ApiProblem;
use App\Repository\RatingRepository;
use App\Security\BackupPasskeyGate;
use App\Security\StepUpGuard;
use App\Service\AuditLogger;
use App\Service\ModerationService;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Lehnt einen wartenden Bewertungs-Kommentar ab (blendet nur den Text aus, der
 * Stern bleibt im Aggregat). Destruktive Moderationsaktion: Backup-Passkey-Gate
 * (>= 2 Passkeys) + frisches Step-up, gespiegelt an VersionReject. Gates VOR der
 * Transaktion; Status-Guard als Sentinel → 409 nach dem Commit.
 */
final class CommentRejectController
{
    #[Route('/api/admin/comments/{id}/reject', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function __invoke(
        int $id,
        RatingRepository $ratings,
        ModerationService $moderation,
        AuditLogger $audit,
        Security $security,
        StepUpGuard $stepUp,
        BackupPasskeyGate $backup,
        EntityManagerInterface $em,
    ): Response {
        /** @var AdminUser $actor */
        $actor = $security->getUser();
        $backup->assertEnough($actor);
        $stepUp->assertFresh();

        $rating = $ratings->find($id) ?? throw new ApiProblem(404, 'Rating not found');

        $conflict = $em->wrapInTransaction(function () use ($moderation, $rating, $audit, $actor, $em): ?string {
            $em->lock($rating, LockMode::PESSIMISTIC_WRITE);
            $em->refresh($rating);
            try {
                $moderation->rejectComment($rating);
            } catch (\RuntimeException $e) {
                return $e->getMessage();
            }
            $audit->log($actor, 'comment.reject', 'comment', (string) $rating->id);

            return null;
        });

        if ($conflict !== null) {
            throw new ApiProblem(409, $conflict);
        }

        return new Response('', 204);
    }
}
