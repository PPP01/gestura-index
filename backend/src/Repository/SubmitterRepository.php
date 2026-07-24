<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Account;
use App\Entity\Submitter;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Datenbankzugriff für Submitter-Entitäten – verwaltet anonyme und
 * konto-gebundene Einreicher, identifiziert über den Argon2id-Hash des
 * Edit-Tokens (der Token selbst wird nie gespeichert).
 *
 * @extends ServiceEntityRepository<Submitter>
 */
class SubmitterRepository extends ServiceEntityRepository
{
    /**
     * Registriert den Repository-Service für die Submitter-Entität im Doctrine-ManagerRegistry.
     */
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Submitter::class);
    }

    /**
     * True, wenn irgendein mit dem Konto verknüpfter Submitter gesperrt ist.
     * Ban-Bündel-Wirkung: wer Trust über das Bündel aggregiert, aggregiert
     * auch Sperren — eine Sperre gegen ein Token wirkt gegen das ganze Konto.
     */
    public function hasBannedForAccount(Account $account): bool
    {
        return (bool) $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->where('s.account = :account')->andWhere('s.banned = true')
            ->setParameter('account', $account)
            ->getQuery()->getSingleScalarResult();
    }

    /**
     * Ältester nicht gesperrter Submitter eines Kontos — deterministische Wahl
     * für Konto-Einreichungen (kein Marker-Feld nötig).
     */
    public function oldestActiveForAccount(Account $account): ?Submitter
    {
        return $this->createQueryBuilder('s')
            ->where('s.account = :account')->andWhere('s.banned = false')
            ->setParameter('account', $account)
            ->orderBy('s.createdAt', 'ASC')->addOrderBy('s.id', 'ASC')
            ->setMaxResults(1)
            ->getQuery()->getOneOrNullResult();
    }

    /**
     * Summe der approvedCounts aller nicht gesperrten Submitter eines Kontos —
     * Grundlage der Trust-Aggregation (migrierter Ruf wirkt sofort weiter).
     */
    public function sumApprovedCountForAccount(Account $account): int
    {
        return (int) $this->createQueryBuilder('s')
            ->select('COALESCE(SUM(s.approvedCount), 0)')
            ->where('s.account = :account')->andWhere('s.banned = false')
            ->setParameter('account', $account)
            ->getQuery()->getSingleScalarResult();
    }
}
