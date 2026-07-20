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

    public function maxSemver(Entry $entry): ?string
    {
        $semvers = array_column(
            $this->createQueryBuilder('v')->select('v.semver')
                ->andWhere('v.entry = :entry')->setParameter('entry', $entry)
                ->getQuery()->getArrayResult(),
            'semver',
        );
        if ($semvers === []) {
            return null;
        }
        usort($semvers, static fn (string $a, string $b): int => version_compare($a, $b));

        return end($semvers);
    }
}
