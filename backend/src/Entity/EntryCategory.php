<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\Category;
use Doctrine\ORM\Mapping as ORM;

/**
 * Pivot-Entity, die einen {@see Entry} mit einer Kategorie verknüpft.
 * Unique-Constraint auf (entry_id, category) verhindert doppelte Zuordnungen.
 */
#[ORM\Entity]
#[ORM\Table(name: 'entry_category')]
#[ORM\UniqueConstraint(columns: ['entry_id', 'category'])]
#[ORM\Index(columns: ['category'])]
class EntryCategory
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Entry::class, inversedBy: 'categories')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public Entry $entry;

    #[ORM\Column(length: 20, enumType: Category::class)]
    public Category $category;

    /**
     * Legt die Kategorie-Verknüpfung für den übergebenen Entry an.
     */
    public function __construct(Entry $entry, Category $category)
    {
        $this->entry = $entry;
        $this->category = $category;
    }
}
