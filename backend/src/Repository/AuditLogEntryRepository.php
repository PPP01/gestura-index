<?php
declare(strict_types=1);
namespace App\Repository;

use App\Entity\AuditLogEntry;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<AuditLogEntry> */
class AuditLogEntryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AuditLogEntry::class);
    }

    /** @return list<AuditLogEntry> */
    public function page(int $page, int $perPage): array
    {
        return $this->createQueryBuilder('a')
            ->orderBy('a.createdAt', 'DESC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery()->getResult();
    }
}
