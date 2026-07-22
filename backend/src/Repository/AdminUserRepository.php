<?php
declare(strict_types=1);
namespace App\Repository;

use App\Entity\AdminUser;
use App\Enum\AdminRole;
use App\Enum\AdminUserStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<AdminUser> */
class AdminUserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AdminUser::class);
    }

    public function findOneByEmail(string $email): ?AdminUser
    {
        return $this->findOneBy(['email' => $email]);
    }

    /** Zählt aktive Admins (role=admin, status=active) – Grundlage für den »letzter Admin«-Schutz vor Disable. */
    public function countActiveAdmins(): int
    {
        return (int) $this->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->andWhere('u.role = :role')
            ->andWhere('u.status = :status')
            ->setParameter('role', AdminRole::Admin)
            ->setParameter('status', AdminUserStatus::Active)
            ->getQuery()->getSingleScalarResult();
    }
}
