<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\Category;
use App\Enum\EntryStatus;
use App\Enum\EntryType;
use App\Repository\EntryRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: EntryRepository::class)]
#[ORM\HasLifecycleCallbacks]
class Entry
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\Column(length: 128, unique: true)]
    public string $formatId;

    #[ORM\Column(length: 10, enumType: EntryType::class)]
    public EntryType $type;

    #[ORM\Column(length: 10, enumType: EntryStatus::class)]
    public EntryStatus $status = EntryStatus::Pending;

    /** @var Collection<int, EntryCategory> */
    #[ORM\OneToMany(mappedBy: 'entry', targetEntity: EntryCategory::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    public Collection $categories;

    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    public array $tags = [];

    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    public array $domains = [];

    #[ORM\Column(type: 'text')]
    public string $searchText = '';

    #[ORM\Column]
    public int $installCount = 0;

    #[ORM\Column]
    public bool $deprecated = false;

    #[ORM\Column(length: 128, nullable: true)]
    public ?string $successorFormatId = null;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $screenshotPath = null;

    #[ORM\ManyToOne(targetEntity: Submitter::class)]
    #[ORM\JoinColumn(nullable: false)]
    public Submitter $submitter;

    #[ORM\OneToOne(targetEntity: EntryVersion::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    public ?EntryVersion $currentVersion = null;

    #[ORM\Column]
    public \DateTimeImmutable $createdAt;

    #[ORM\Column]
    public \DateTimeImmutable $updatedAt;

    public function __construct(string $formatId, EntryType $type, Submitter $submitter)
    {
        $this->formatId = $formatId;
        $this->type = $type;
        $this->submitter = $submitter;
        $this->categories = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    /** @param list<Category> $categories */
    public function setCategories(array $categories): void
    {
        $this->categories->clear();
        foreach (array_unique($categories, SORT_REGULAR) as $category) {
            $this->categories->add(new EntryCategory($this, $category));
        }
    }

    /** @return list<string> */
    public function categoryKeys(): array
    {
        return array_values(array_map(
            static fn (EntryCategory $c): string => $c->category->value,
            $this->categories->toArray(),
        ));
    }
}
