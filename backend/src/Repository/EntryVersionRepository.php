<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\EntryVersion;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<EntryVersion> */
class EntryVersionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EntryVersion::class);
    }
}
