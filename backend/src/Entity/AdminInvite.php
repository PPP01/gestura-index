<?php
declare(strict_types=1);
namespace App\Entity;

use App\Enum\AdminRole;
use App\Repository\AdminInviteRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AdminInviteRepository::class)]
#[ORM\Table(name: 'admin_invite')]
class AdminInvite
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\Column(length: 16, unique: true)]
    public string $tokenSelector;

    #[ORM\Column(length: 255)]
    public string $tokenHash;

    #[ORM\ManyToOne(targetEntity: AdminUser::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public AdminUser $adminUser;

    #[ORM\Column(length: 10, enumType: AdminRole::class)]
    public AdminRole $role;

    #[ORM\ManyToOne(targetEntity: AdminUser::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    public ?AdminUser $createdBy = null;

    #[ORM\Column]
    public \DateTimeImmutable $createdAt;

    #[ORM\Column]
    public \DateTimeImmutable $expiresAt;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $usedAt = null;

    public function __construct(string $tokenSelector, string $tokenHash, AdminUser $adminUser, AdminRole $role, \DateTimeImmutable $expiresAt)
    {
        $this->tokenSelector = $tokenSelector;
        $this->tokenHash = $tokenHash;
        $this->adminUser = $adminUser;
        $this->role = $role;
        $this->expiresAt = $expiresAt;
        $this->createdAt = new \DateTimeImmutable();
    }
}
