<?php
declare(strict_types=1);
namespace App\Entity;

use App\Repository\WebAuthnCredentialRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: WebAuthnCredentialRepository::class)]
#[ORM\Table(name: 'webauthn_credential')]
class WebAuthnCredential
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: AdminUser::class, inversedBy: 'credentials')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public AdminUser $adminUser;

    /** Base64URL der publicKeyCredentialId (Lookup-Schlüssel). */
    #[ORM\Column(length: 255, unique: true)]
    public string $credentialId;

    /** Vollständiger, serialisierter CredentialRecord (JSON) — Wahrheitsquelle für die Assertion. */
    #[ORM\Column(type: 'text')]
    public string $source;

    #[ORM\Column(length: 64)]
    public string $label;

    #[ORM\Column(length: 64, nullable: true)]
    public ?string $aaguid = null;

    #[ORM\Column]
    public \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $lastUsedAt = null;

    public function __construct(AdminUser $adminUser, string $credentialId, string $source, string $label)
    {
        $this->adminUser = $adminUser;
        $this->credentialId = $credentialId;
        $this->source = $source;
        $this->label = $label;
        $this->createdAt = new \DateTimeImmutable();
    }
}
