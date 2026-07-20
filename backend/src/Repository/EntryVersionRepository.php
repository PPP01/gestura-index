<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Entry;
use App\Entity\EntryVersion;
use App\Enum\VersionStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<EntryVersion> */
class EntryVersionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EntryVersion::class);
    }

    /** @return list<EntryVersion> */
    public function findApproved(Entry $entry): array
    {
        return $this->findBy(['entry' => $entry, 'status' => VersionStatus::Approved], ['submittedAt' => 'DESC', 'id' => 'DESC']);
    }

    public function findOneApproved(Entry $entry, string $semver): ?EntryVersion
    {
        return $this->findOneBy(['entry' => $entry, 'semver' => $semver, 'status' => VersionStatus::Approved]);
    }
}
