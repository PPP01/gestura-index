<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AccountRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Anonymes End-Nutzer-Konto (Phase 3), identifiziert über ein geheimes Bearer-
 * Token. Strikt getrennt von AdminUser: keine Rolle, keine E-Mail, kein
 * Username. Das Token selbst wird nie gespeichert; nur tokenSelector (16
 * Zeichen, öffentlich) und tokenHash (Argon2id). lastSeenAt dient allein dem
 * Aufräumen inaktiver Konten.
 */
#[ORM\Entity(repositoryClass: AccountRepository::class)]
#[ORM\Table(name: 'account')]
class Account
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\Column(length: 16, unique: true)]
    public string $tokenSelector;

    #[ORM\Column(length: 255)]
    public string $tokenHash;

    #[ORM\Column]
    public \DateTimeImmutable $createdAt;

    #[ORM\Column]
    public \DateTimeImmutable $lastSeenAt;

    public function __construct(string $tokenSelector, string $tokenHash)
    {
        $this->tokenSelector = $tokenSelector;
        $this->tokenHash = $tokenHash;
        $this->createdAt = new \DateTimeImmutable();
        $this->lastSeenAt = $this->createdAt;
    }
}
