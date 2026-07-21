<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\VersionStatus;
use App\Repository\EntryVersionRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Eine einzelne SemVer-Version eines Entry mit validiertem JSON-Payload.
 * Unique-Constraint auf (entry_id, semver) verhindert Duplikate; contentHash
 * ermöglicht schnelle Inhaltsprüfung ohne erneutes Deserialisieren.
 */
#[ORM\Entity(repositoryClass: EntryVersionRepository::class)]
#[ORM\Table(name: 'entry_version')]
#[ORM\UniqueConstraint(columns: ['entry_id', 'semver'])]
#[ORM\Index(columns: ['content_hash'])]
class EntryVersion
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Entry::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public Entry $entry;

    #[ORM\Column(length: 17)]
    public string $semver;

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json')]
    public array $payload;

    #[ORM\Column(length: 64)]
    public string $contentHash;

    #[ORM\Column(type: 'text', nullable: true)]
    public ?string $changelog = null;

    #[ORM\Column(length: 10, enumType: VersionStatus::class)]
    public VersionStatus $status = VersionStatus::Pending;

    #[ORM\Column]
    public bool $hasTransformCode = false;

    #[ORM\Column]
    public \DateTimeImmutable $submittedAt;

    /**
     * Erstellt eine neue Version und setzt submittedAt auf den aktuellen Zeitpunkt.
     *
     * @param array<string, mixed> $payload
     */
    public function __construct(Entry $entry, string $semver, array $payload, string $contentHash)
    {
        $this->entry = $entry;
        $this->semver = $semver;
        $this->payload = $payload;
        $this->contentHash = $contentHash;
        $this->submittedAt = new \DateTimeImmutable();
    }
}
