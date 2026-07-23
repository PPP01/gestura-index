<?php
declare(strict_types=1);
namespace App\Repository;

use App\Entity\AdminUser;
use App\Enum\AdminRole;
use App\Enum\AdminUserStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
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

    /**
     * Sperrt alle aktiven Admin-Zeilen per SELECT … FOR UPDATE. Serialisiert
     * konkurrierende Disable-Operationen: zwei gleichzeitige Requests, die je
     * einen aktiven Admin deaktivieren wollen, überlappen auf denselben Zeilen
     * und werden dadurch nacheinander abgearbeitet – der »letzter Admin«-Schutz
     * lässt sich so nicht per Race umgehen. Muss innerhalb einer Transaktion
     * laufen (Doctrine verlangt das bei PESSIMISTIC_WRITE).
     */
    public function lockActiveAdmins(): void
    {
        $this->createQueryBuilder('u')
            ->andWhere('u.role = :role')
            ->andWhere('u.status = :status')
            ->setParameter('role', AdminRole::Admin)
            ->setParameter('status', AdminUserStatus::Active)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getResult();
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
