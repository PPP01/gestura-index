<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Entry;
use App\Enum\Category;
use App\Enum\EntryStatus;
use App\Enum\EntryType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Entry> */
class EntryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Entry::class);
    }

    /** @return array{items: list<\App\Entity\Entry>, total: int} */
    public function search(
        ?string $q,
        ?string $site,
        ?Category $category,
        ?string $tag,
        ?EntryType $type,
        string $sort,
        int $page,
        int $perPage,
    ): array {
        $qb = $this->createQueryBuilder('e')
            ->andWhere('e.status = :published')
            ->setParameter('published', EntryStatus::Published);

        if ($type !== null) {
            $qb->andWhere('e.type = :type')->setParameter('type', $type);
        }
        if ($category !== null) {
            $qb->innerJoin('e.categories', 'c')
                ->andWhere('c.category = :category')
                ->setParameter('category', $category);
        }
        if ($q !== null && $q !== '') {
            $qb->andWhere('e.searchText LIKE :q')
                ->setParameter('q', '%' . mb_strtolower($q) . '%');
        }
        // tags/domains sind JSON-Arrays normalisierter Strings; das
        // LIKE auf den kodierten Wert ersetzt JSON_CONTAINS, das DQL
        // ohne Zusatzbundle nicht kennt.
        if ($site !== null && $site !== '') {
            $qb->andWhere('e.domains LIKE :site')
                ->setParameter('site', '%' . json_encode(mb_strtolower($site)) . '%');
        }
        if ($tag !== null && $tag !== '') {
            $qb->andWhere('e.tags LIKE :tag')
                ->setParameter('tag', '%' . json_encode(mb_strtolower($tag)) . '%');
        }

        $total = (int) (clone $qb)->select('COUNT(DISTINCT e.id)')->getQuery()->getSingleScalarResult();

        $qb->orderBy($sort === 'installs' ? 'e.installCount' : 'e.createdAt', 'DESC')
            ->addOrderBy('e.id', 'DESC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage);

        return ['items' => $qb->getQuery()->getResult(), 'total' => $total];
    }
}
