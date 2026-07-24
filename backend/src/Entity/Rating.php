<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\CommentStatus;
use App\Repository\RatingRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Sterne-Bewertung eines Kontos zu einem Eintrag (Phase 3 D). Genau eine
 * Bewertung pro Konto und Eintrag (UNIQUE). stars (1–5) zählt immer sofort in
 * das lesend berechnete öffentliche Aggregat; der optionale comment durchläuft
 * eine Hybrid-Moderation nach Vertrauen (commentStatus). Beide FKs tragen
 * DB-seitiges ON DELETE CASCADE, damit Konto-Löschung (inkl. des
 * index:account:prune-Bulk-DELETE an der ORM vorbei) und Eintrag-Löschung die
 * Bewertung zuverlässig mitnehmen – da die Aggregate lesend berechnet werden,
 * stimmen sie danach ohne Nachpflege.
 */
#[ORM\Entity(repositoryClass: RatingRepository::class)]
#[ORM\Table(name: 'rating')]
#[ORM\UniqueConstraint(columns: ['account_id', 'entry_id'])]
class Rating
{
    /** Maximale Kommentarlänge in Zeichen. */
    public const MAX_COMMENT_LENGTH = 500;

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Account::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public Account $account;

    #[ORM\ManyToOne(targetEntity: Entry::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public Entry $entry;

    #[ORM\Column]
    public int $stars;

    #[ORM\Column(length: self::MAX_COMMENT_LENGTH, nullable: true)]
    public ?string $comment = null;

    #[ORM\Column(length: 10, enumType: CommentStatus::class)]
    public CommentStatus $commentStatus = CommentStatus::Approved;

    #[ORM\Column]
    public \DateTimeImmutable $createdAt;

    #[ORM\Column]
    public \DateTimeImmutable $updatedAt;

    public function __construct(Account $account, Entry $entry, int $stars)
    {
        $this->account = $account;
        $this->entry = $entry;
        $this->stars = $stars;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }
}
