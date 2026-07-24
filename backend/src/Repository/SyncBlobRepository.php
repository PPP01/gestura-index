<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SyncBlob;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SyncBlob>
 */
final class SyncBlobRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SyncBlob::class);
    }
}
