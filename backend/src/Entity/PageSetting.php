<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PageSettingRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Sichtbarkeits-Flag einer schaltbaren Marketing-Seite. pageKey ist der
 * SvelteKit-Slug (z. B. »vergleich«). Fehlt eine Zeile, gilt die Seite als aktiv.
 */
#[ORM\Entity(repositoryClass: PageSettingRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_page_setting_key', columns: ['page_key'])]
class PageSetting
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\Column(length: 64)]
    public string $pageKey;

    #[ORM\Column]
    public bool $enabled = true;

    #[ORM\Column]
    public \DateTimeImmutable $updatedAt;

    #[ORM\Column(length: 190, nullable: true)]
    public ?string $updatedBy = null;

    public function __construct(string $pageKey, bool $enabled = true)
    {
        $this->pageKey = $pageKey;
        $this->enabled = $enabled;
        $this->updatedAt = new \DateTimeImmutable();
    }
}
