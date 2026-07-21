<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Entry;
use App\Enum\Category;
use App\Enum\EntryStatus;
use App\Enum\EntryType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Datenbankzugriff für Entry-Entitäten – kapselt Volltext-, Filter- und
 * Paginierungslogik für die öffentliche Eintrags-Suche.
 *
 * @extends ServiceEntityRepository<Entry>
 */
class EntryRepository extends ServiceEntityRepository
{
    /**
     * Registriert den Repository-Service für die Entry-Entität im Doctrine-ManagerRegistry.
     */
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Entry::class);
    }

    /**
     * Durchsucht veröffentlichte Einträge mit optionalen Filtern (Volltext,
     * Domain, Kategorie, Tag, Typ) und liefert das paginierte Ergebnis sowie
     * die Gesamtanzahl für die Paginierungsanzeige. Sortierung wahlweise
     * nach Installationszähler (»installs«) oder Erstellungsdatum (neueste zuerst).
     *
     * @return array{items: list<\App\Entity\Entry>, total: int}
     */
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
                ->setParameter('q', '%' . self::escapeLike(mb_strtolower($q)) . '%');
        }
        // tags/domains sind JSON-Arrays normalisierter Strings; das
        // LIKE auf den kodierten Wert ersetzt JSON_CONTAINS, das DQL
        // ohne Zusatzbundle nicht kennt.
        if ($site !== null && $site !== '') {
            $qb->andWhere('e.domains LIKE :site')
                ->setParameter('site', '%' . self::escapeLike(json_encode(mb_strtolower($site))) . '%');
        }
        if ($tag !== null && $tag !== '') {
            $qb->andWhere('e.tags LIKE :tag')
                ->setParameter('tag', '%' . self::escapeLike(json_encode(mb_strtolower($tag))) . '%');
        }

        $total = (int) (clone $qb)->select('COUNT(DISTINCT e.id)')->getQuery()->getSingleScalarResult();

        $qb->orderBy($sort === 'installs' ? 'e.installCount' : 'e.createdAt', 'DESC')
            ->addOrderBy('e.id', 'DESC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage);

        return ['items' => $qb->getQuery()->getResult(), 'total' => $total];
    }

    /**
     * Erhöht den anonymen Install-Zähler atomar in der Datenbank
     * (UPDATE … SET install_count = install_count + 1). Ein Read-modify-write
     * über das geladene Entity-Objekt würde bei parallelen Requests Zählungen
     * verlieren; das direkte SQL-UPDATE ist rennsicher.
     */
    public function incrementInstallCount(Entry $entry): void
    {
        $this->getEntityManager()->createQuery(
            'UPDATE App\Entity\Entry e SET e.installCount = e.installCount + 1 WHERE e.id = :id',
        )->setParameter('id', $entry->id)->execute();
    }

    /**
     * Maskiert LIKE-Sonderzeichen (%, _, \) in Nutzereingaben, damit sie
     * als Literale und nicht als Platzhalter ausgewertet werden.
     */
    // MariaDB nutzt Backslash als Default-Escape-Zeichen in LIKE; ein
    // ESCAPE-Zusatz in DQL ist daher nicht nötig. Ohne dieses Escaping
    // würden Nutzereingaben wie "%" oder "_" den Filter faktisch
    // außer Kraft setzen (matcht dann fast alles statt nichts).
    private static function escapeLike(string $value): string
    {
        return addcslashes($value, '%_\\');
    }
}
