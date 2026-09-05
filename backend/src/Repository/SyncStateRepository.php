<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SyncState;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Zugriff auf Sync-Stände – ausschließlich über den Locator-HASH; eine
 * Methode, die den Klartext-Locator entgegennimmt, gibt es hier bewusst nicht.
 *
 * Mehrere Methoden meiden die Entity-Hydration bewusst: die Spalte »payload«
 * fasst bis zu 512 KiB, und Doctrine kennt kein spaltenweises Nachladen –
 * eine geladene Entity zieht sie immer mit. Wo die Nutzlast nicht gebraucht
 * wird (Liste, Löschen, Fristauffrischung), wird deshalb spaltenweise
 * selektiert oder direkt per DQL geschrieben.
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

    /**
     * Lädt den Stand und sperrt ihn in einem Zug (SELECT … FOR UPDATE).
     * Laden und Sperren sind derselbe Vorgang – ein nachgelagertes refresh()
     * entfällt, und damit auch das zweite vollständige Lesen der Zeile.
     *
     * Setzt eine aktive Transaktion voraus (Doctrine verlangt sie für
     * PESSIMISTIC_WRITE).
     */
    public function findOneForUpdate(string $locatorHash, string $stateId): ?SyncState
    {
        return $this->createQueryBuilder('s')
            ->where('s.locatorHash = :hash')->setParameter('hash', $locatorHash)
            ->andWhere('s.stateId = :state')->setParameter('state', $stateId)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getOneOrNullResult();
    }

    /**
     * Die Liste für /sync/list – OHNE payload. Spaltenweise statt als Entity:
     * bei fünf Ständen à 512 KiB wären das 2,5 MiB, die der Endpunkt nie
     * ausgibt. Genau dafür ist ein Stand in zwei Chiffrate geteilt; die
     * Ersparnis soll nicht erst auf der HTTP-Seite anfangen.
     *
     * @return list<array{stateId: string, sizeBytes: int, updatedAt: \DateTimeImmutable, meta: string}>
     */
    public function listByLocator(string $locatorHash): array
    {
        return $this->createQueryBuilder('s')
            ->select('s.stateId', 's.sizeBytes', 's.updatedAt', 's.meta')
            ->where('s.locatorHash = :hash')->setParameter('hash', $locatorHash)
            ->orderBy('s.updatedAt', 'DESC')->addOrderBy('s.id', 'DESC')
            ->getQuery()->getArrayResult();
    }

    /**
     * Frischt die Aufbewahrungsfrist aller Stände eines Locators auf, aber
     * nur, wenn sie älter als $notAfter ist. Ein einziges UPDATE statt bis zu
     * fünf einzelner, und auf einem wiederholt gelesenen Locator gar keines:
     * die Frist läuft über 12 Monate, eine Auflösung von Sekunden wäre um
     * Größenordnungen zu fein für die Platten-I/O, die sie kostet.
     *
     * updatedAt bleibt unberührt – das ist der Wert, den der Client zeigt.
     */
    public function touchLocator(string $locatorHash, \DateTimeImmutable $now, \DateTimeImmutable $notAfter): int
    {
        return (int) $this->createQueryBuilder('s')
            ->update()
            ->set('s.lastAccessAt', ':now')->setParameter('now', $now)
            ->where('s.locatorHash = :hash')->setParameter('hash', $locatorHash)
            ->andWhere('s.lastAccessAt < :notAfter')->setParameter('notAfter', $notAfter)
            ->getQuery()->execute();
    }

    public function countByLocator(string $locatorHash): int
    {
        return (int) $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->where('s.locatorHash = :hash')->setParameter('hash', $locatorHash)
            ->getQuery()->getSingleScalarResult();
    }

    /** Löscht einen einzelnen Stand; liefert 1, wenn es ihn gab, sonst 0. */
    public function deleteOne(string $locatorHash, string $stateId): int
    {
        return (int) $this->createQueryBuilder('s')
            ->delete()
            ->where('s.locatorHash = :hash')->setParameter('hash', $locatorHash)
            ->andWhere('s.stateId = :state')->setParameter('state', $stateId)
            ->getQuery()->execute();
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
