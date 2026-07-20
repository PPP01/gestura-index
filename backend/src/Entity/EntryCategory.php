<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\Category;
use Doctrine\ORM\Mapping as ORM;

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

    public function __construct(Entry $entry, Category $category)
    {
        $this->entry = $entry;
        $this->category = $category;
    }
}
