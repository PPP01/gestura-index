<?php
declare(strict_types=1);
namespace App\Repository;

use App\Entity\AdminInvite;
use App\Entity\AdminUser;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<AdminInvite> */
class AdminInviteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AdminInvite::class);
    }

    public function findOneBySelector(string $selector): ?AdminInvite
    {
        return $this->findOneBy(['tokenSelector' => $selector]);
    }

    /**
     * Alle noch nicht verbrauchten, noch nicht abgelaufenen Invites eines
     * Nutzers – Grundlage, um bei erfolgreicher Registrierung oder einer
     * neuen Einladung alte Geschwister-Tokens zu invalidieren (Replay-Schutz).
     *
     * @return list<AdminInvite>
     */
    public function findUnusedForUser(AdminUser $user): array
    {
        return $this->createQueryBuilder('i')
            ->andWhere('i.adminUser = :user')
            ->andWhere('i.usedAt IS NULL')
            ->andWhere('i.expiresAt >= :now')
            ->setParameter('user', $user)
            ->setParameter('now', new \DateTimeImmutable())
            ->getQuery()
            ->getResult();
    }
}
