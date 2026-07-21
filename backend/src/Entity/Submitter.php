<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SubmitterRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Anonymer Einreicher eines Entry – identifiziert über einen geheimen Edit-Token.
 * Der Token selbst wird nie gespeichert; nur tokenSelector (öffentlich, 16 Zeichen)
 * und tokenHash (Argon2id) werden persistiert. approvedCount steuert das Vertrauensniveau
 * für die Moderationslogik.
 */
#[ORM\Entity(repositoryClass: SubmitterRepository::class)]
class Submitter
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\Column(length: 16, unique: true)]
    public string $tokenSelector;

    #[ORM\Column(length: 255)]
    public string $tokenHash;

    #[ORM\Column]
    public int $approvedCount = 0;

    #[ORM\Column]
    public bool $banned = false;

    #[ORM\Column]
    public \DateTimeImmutable $createdAt;

    /**
     * Erstellt einen neuen Submitter aus Selektor und Argon2id-Hash des Edit-Tokens.
     */
    public function __construct(string $tokenSelector, string $tokenHash)
    {
        $this->tokenSelector = $tokenSelector;
        $this->tokenHash = $tokenHash;
        $this->createdAt = new \DateTimeImmutable();
    }
}
