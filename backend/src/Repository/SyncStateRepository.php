<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SyncState;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Zugriff auf Sync-Stände – ausschließlich über den Locator-HASH; eine
 * Methode, die den Klartext-Locator entgegennimmt, gibt es hier bewusst nicht.
 *
 * @extends ServiceEntityRepository<SyncState>
 */
class SyncStateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SyncState::class);
    }

    public function findOneByLocatorAndState(string $locatorHash, string $stateId): ?SyncState
    {
        return $this->findOneBy(['locatorHash' => $locatorHash, 'stateId' => $stateId]);
    }

    /** @return list<SyncState> Neueste zuerst – die Liste ist normalerweise höchstens 5 lang. */
    public function findByLocator(string $locatorHash): array
    {
        return array_values($this->findBy(['locatorHash' => $locatorHash], ['updatedAt' => 'DESC', 'id' => 'DESC']));
    }

    public function countByLocator(string $locatorHash): int
    {
        return (int) $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->where('s.locatorHash = :hash')->setParameter('hash', $locatorHash)
            ->getQuery()->getSingleScalarResult();
    }

    /** Löscht alle Stände eines Locators; liefert die Anzahl. */
    public function deleteByLocator(string $locatorHash): int
    {
        return (int) $this->createQueryBuilder('s')
            ->delete()
            ->where('s.locatorHash = :hash')->setParameter('hash', $locatorHash)
            ->getQuery()->execute();
    }

    /**
     * Aufbewahrung: löscht Stände, die seit dem Stichtag weder gelesen noch
     * geschrieben wurden. Bulk-DELETE per DQL – SyncState hat keine
     * abhängigen Entities, die eine ORM-Kaskade brauchen würden. Wer das
     * ändert, muss diese Stelle mitziehen (siehe .claude/lessons.md zum
     * gleichen Muster bei index:account:prune).
     */
    public function deleteUnusedBefore(\DateTimeImmutable $cutoff): int
    {
        return (int) $this->createQueryBuilder('s')
            ->delete()
            ->where('s.lastAccessAt < :cutoff')->setParameter('cutoff', $cutoff)
            ->getQuery()->execute();
    }
}
