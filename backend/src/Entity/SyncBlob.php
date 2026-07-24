<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SyncBlobRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Ein clientseitig verschlüsselter Sync-Blob eines End-Nutzer-Kontos (Phase 3,
 * Zero-Knowledge-Settings-Sync). Der Server behandelt ciphertext als opaken
 * String – kein Entschlüsseln, keine Struktur-Kenntnis, kein Merge. version
 * ist der monoton steigende Zähler fürs optimistische Locking (PUT mit
 * baseVersion). Der FK trägt DB-seitiges ON DELETE CASCADE, damit auch das
 * DQL-Bulk-DELETE von index:account:prune (umgeht die ORM-Kaskade) die Blobs
 * zuverlässig mit entfernt.
 */
#[ORM\Entity(repositoryClass: SyncBlobRepository::class)]
#[ORM\Table(name: 'sync_blob')]
#[ORM\UniqueConstraint(columns: ['account_id', 'collection'])]
class SyncBlob
{
    /** Erlaubte Slots – hart begrenzt, kein Free-Form-Storage. */
    public const COLLECTIONS = ['settings', 'menus', 'engines'];

    /** Maximale Chiffrat-Größe in Bytes (256 KB) – Missbrauchsbremse. */
    public const MAX_CIPHERTEXT_BYTES = 262144;

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Account::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public Account $account;

    #[ORM\Column(length: 16)]
    public string $collection;

    #[ORM\Column(type: 'text', length: self::MAX_CIPHERTEXT_BYTES)]
    public string $ciphertext;

    #[ORM\Column]
    public int $version = 1;

    #[ORM\Column]
    public \DateTimeImmutable $updatedAt;

    public function __construct(Account $account, string $collection, string $ciphertext)
    {
        $this->account = $account;
        $this->collection = $collection;
        $this->ciphertext = $ciphertext;
        $this->updatedAt = new \DateTimeImmutable();
    }
}
