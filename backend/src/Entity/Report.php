<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ReportReason;
use App\Enum\ReportStatus;
use App\Repository\ReportRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Nutzermeldung auf einen Entry (Spam, fehlerhafte Links, irreführende Inhalte, Rechtsverstoß).
 * Startet immer mit Status {@see \App\Enum\ReportStatus::Open}.
 */
#[ORM\Entity(repositoryClass: ReportRepository::class)]
class Report
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Entry::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public Entry $entry;

    #[ORM\Column(length: 20, enumType: ReportReason::class)]
    public ReportReason $reason;

    #[ORM\Column(type: 'text', nullable: true)]
    public ?string $comment = null;

    #[ORM\Column(length: 10, enumType: ReportStatus::class)]
    public ReportStatus $status = ReportStatus::Open;

    #[ORM\Column]
    public \DateTimeImmutable $createdAt;

    /**
     * Erstellt eine neue Meldung und setzt createdAt auf den aktuellen Zeitpunkt.
     */
    public function __construct(Entry $entry, ReportReason $reason, ?string $comment)
    {
        $this->entry = $entry;
        $this->reason = $reason;
        $this->comment = $comment;
        $this->createdAt = new \DateTimeImmutable();
    }
}
