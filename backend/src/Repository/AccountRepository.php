<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Account;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Account>
 */
final class AccountRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Account::class);
    }

    /**
     * Löscht alle Konten, deren letzter Aktivitätszeitpunkt beim Ausführen des
     * DELETE noch vor dem Stichtag liegt. Die Bedingung ist Teil desselben
     * Statements wie die Löschung: Ein zwischenzeitlich aktualisiertes Konto
     * wird dadurch nicht aufgrund eines zuvor gelesenen Zustands entfernt.
     *
     * ACHTUNG (Sub-Projekt F): Ein DQL-Bulk-DELETE umgeht die ORM-Kaskade.
     * Sobald abhängige Entities mit FK auf Account existieren (z. B. SyncBlob),
     * werden sie hier NICHT ORM-seitig mitgelöscht — dann zwingend DB-seitiges
     * ON DELETE CASCADE setzen oder die Blobs vorher explizit entfernen.
     *
     * @return int Anzahl der gelöschten Konten
     */
    public function deleteInactiveBefore(\DateTimeImmutable $cutoff): int
    {
        $deleted = $this->getEntityManager()->createQueryBuilder()
            ->delete(Account::class, 'a')
            ->where('a.lastSeenAt < :cutoff')
            ->setParameter('cutoff', $cutoff)
            ->getQuery()
            ->execute();

        if (!\is_int($deleted)) {
            throw new \LogicException('Doctrine lieferte keine Anzahl gelöschter Konten');
        }

        return $deleted;
    }
}
