<?php
declare(strict_types=1);
namespace App\Entity;

use App\Enum\AdminRole;
use App\Enum\AdminUserStatus;
use App\Repository\AdminUserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity(repositoryClass: AdminUserRepository::class)]
#[ORM\Table(name: 'admin_user')]
class AdminUser implements UserInterface
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\Column(length: 190, unique: true)]
    public string $email;

    #[ORM\Column(length: 190)]
    public string $displayName;

    #[ORM\Column(length: 10, enumType: AdminRole::class)]
    public AdminRole $role;

    #[ORM\Column(length: 10, enumType: AdminUserStatus::class)]
    public AdminUserStatus $status = AdminUserStatus::Invited;

    #[ORM\Column]
    public \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $lastLoginAt = null;

    /** @var Collection<int, WebAuthnCredential> */
    #[ORM\OneToMany(mappedBy: 'adminUser', targetEntity: WebAuthnCredential::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    public Collection $credentials;

    public function __construct(string $displayName, string $email, AdminRole $role)
    {
        $this->displayName = $displayName;
        $this->email = $email;
        $this->role = $role;
        $this->createdAt = new \DateTimeImmutable();
        $this->credentials = new ArrayCollection();
    }

    public function getRoles(): array
    {
        return $this->role === AdminRole::Admin ? ['ROLE_ADMIN'] : ['ROLE_MODERATOR'];
    }

    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    public function eraseCredentials(): void
    {
    }

    public function credentialCount(): int
    {
        return $this->credentials->count();
    }
}
