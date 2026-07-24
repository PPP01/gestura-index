<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Account;
use App\Entity\Entry;
use App\Entity\Rating;
use App\Enum\CommentStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Rating>
 */
final class RatingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Rating::class);
    }

    public function findForAccountAndEntry(Account $account, Entry $entry): ?Rating
    {
        return $this->findOneBy(['account' => $account, 'entry' => $entry]);
    }

    /**
     * Aggregiert Ø-Sterne und Anzahl je Eintrag in EINER gruppierten Query
     * (kein N+1). Fehlende IDs erscheinen nicht in der Map.
     *
     * @param int[] $entryIds
     *
     * @return array<int, array{average: float, count: int}> keyed by entry id
     */
    public function aggregatesFor(array $entryIds): array
    {
        if ($entryIds === []) {
            return [];
        }

        $rows = $this->createQueryBuilder('r')
            ->select('IDENTITY(r.entry) AS entryId', 'AVG(r.stars) AS average', 'COUNT(r.id) AS cnt')
            ->where('r.entry IN (:ids)')
            ->setParameter('ids', $entryIds)
            ->groupBy('r.entry')
            ->getQuery()
            ->getResult();

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['entryId']] = [
                'average' => round((float) $row['average'], 1),
                'count' => (int) $row['cnt'],
            ];
        }

        return $out;
    }

    /**
     * Paginierte, freigegebene Kommentare eines Eintrags (neueste zuerst).
     *
     * @return Rating[]
     */
    public function approvedComments(Entry $entry, int $offset, int $limit): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.entry = :entry')
            ->andWhere('r.comment IS NOT NULL')
            ->andWhere('r.commentStatus = :approved')
            ->setParameter('entry', $entry)
            ->setParameter('approved', CommentStatus::Approved)
            ->orderBy('r.createdAt', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function countApprovedComments(Entry $entry): int
    {
        return (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->where('r.entry = :entry')
            ->andWhere('r.comment IS NOT NULL')
            ->andWhere('r.commentStatus = :approved')
            ->setParameter('entry', $entry)
            ->setParameter('approved', CommentStatus::Approved)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Alle Bewertungen mit wartendem Kommentar (Moderations-Warteschlange),
     * älteste zuerst.
     *
     * @return Rating[]
     */
    public function pendingComments(): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.comment IS NOT NULL')
            ->andWhere('r.commentStatus = :pending')
            ->setParameter('pending', CommentStatus::Pending)
            ->orderBy('r.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
