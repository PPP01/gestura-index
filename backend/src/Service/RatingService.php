<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Account;
use App\Entity\Entry;
use App\Entity\Rating;
use App\Enum\CommentStatus;
use App\Exception\ApiProblem;
use App\Repository\RatingRepository;
use App\Repository\SubmitterRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Schreiblogik für Bewertungen (Phase 3 D): Upsert und Löschen der einen
 * Bewertung pro Konto & Eintrag, inklusive Anti-Gaming-Guards und der
 * Trust-Hybrid-Entscheidung für den Kommentar-Moderationsstatus.
 */
final class RatingService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RatingRepository $ratings,
        private readonly SubmitterRepository $submitters,
    ) {
    }

    /**
     * Legt die Bewertung des Kontos für den Eintrag an oder aktualisiert sie.
     * Der Stern zählt immer sofort; ein gesetzter Kommentar wird nach Vertrauen
     * sofort freigegeben (approved) oder in die Warteschlange gestellt (pending).
     *
     * @throws ApiProblem 403 bei eigenem Eintrag oder gesperrtem Konto-Bündel
     */
    public function upsert(Account $account, Entry $entry, int $stars, ?string $comment): Rating
    {
        // Anti-Gaming: das eigene (konto-verknüpfte) Werk nicht bewerten.
        if ($entry->submitter->account?->id === $account->id) {
            throw new ApiProblem(403, 'Cannot rate your own entry');
        }
        // Ban-Bündel (aus C): ein gesperrter Ruf darf nicht bewerten.
        if ($this->submitters->hasBannedForAccount($account)) {
            throw new ApiProblem(403, 'Account is banned');
        }

        $rating = $this->ratings->findForAccountAndEntry($account, $entry);
        if ($rating === null) {
            $rating = new Rating($account, $entry, $stars);
            $this->em->persist($rating);
        } else {
            $rating->stars = $stars;
            $rating->updatedAt = new \DateTimeImmutable();
        }

        if ($comment === null || $comment === '') {
            $rating->comment = null;
            $rating->commentStatus = CommentStatus::Approved; // nichts zu moderieren
        } else {
            $rating->comment = $comment;
            $trusted = $this->submitters->sumApprovedCountForAccount($account) >= SubmissionService::TRUST_THRESHOLD;
            $rating->commentStatus = $trusted ? CommentStatus::Approved : CommentStatus::Pending;
        }

        $this->em->flush();

        return $rating;
    }

    /** Entfernt die Bewertung des Kontos für den Eintrag; false, wenn keine existiert. */
    public function delete(Account $account, Entry $entry): bool
    {
        $rating = $this->ratings->findForAccountAndEntry($account, $entry);
        if ($rating === null) {
            return false;
        }

        $this->em->remove($rating);
        $this->em->flush();

        return true;
    }
}
