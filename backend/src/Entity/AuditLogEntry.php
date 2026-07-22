<?php
declare(strict_types=1);
namespace App\Entity;

use App\Repository\AuditLogEntryRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AuditLogEntryRepository::class)]
#[ORM\Table(name: 'audit_log_entry')]
#[ORM\Index(columns: ['created_at'])]
class AuditLogEntry
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: AdminUser::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    public ?AdminUser $actor = null;

    #[ORM\Column(length: 64)]
    public string $action;

    #[ORM\Column(length: 32, nullable: true)]
    public ?string $targetType = null;

    #[ORM\Column(length: 64, nullable: true)]
    public ?string $targetId = null;

    #[ORM\Column(type: 'json', nullable: true)]
    public ?array $detail = null;

    #[ORM\Column]
    public \DateTimeImmutable $createdAt;

    public function __construct(?AdminUser $actor, string $action, ?string $targetType = null, ?string $targetId = null, ?array $detail = null)
    {
        $this->actor = $actor;
        $this->action = $action;
        $this->targetType = $targetType;
        $this->targetId = $targetId;
        $this->detail = $detail;
        $this->createdAt = new \DateTimeImmutable();
    }
}
