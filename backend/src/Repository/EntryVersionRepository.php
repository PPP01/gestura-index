<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Entry;
use App\Entity\EntryVersion;
use App\Enum\VersionStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Datenbankzugriff für EntryVersion-Entitäten – stellt approbierte
 * Versionsabfragen und SemVer-Vergleiche für Eintrags-Versionshistorie
 * und Update-Checks bereit.
 *
 * @extends ServiceEntityRepository<EntryVersion>
 */
class EntryVersionRepository extends ServiceEntityRepository
{
    /**
     * Registriert den Repository-Service für die EntryVersion-Entität im Doctrine-ManagerRegistry.
     */
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EntryVersion::class);
    }

    /**
     * Liefert alle approbierten Versionen eines Eintrags, absteigend nach
     * Einreichungsdatum und ID sortiert.
     *
     * @return list<EntryVersion>
     */
    public function findApproved(Entry $entry): array
    {
        return $this->findBy(['entry' => $entry, 'status' => VersionStatus::Approved], ['submittedAt' => 'DESC', 'id' => 'DESC']);
    }

    /**
     * Sucht eine spezifische approbierte Version anhand Eintrag und SemVer-String.
     * Gibt null zurück, wenn keine passende Version existiert oder sie noch
     * nicht approbiert ist.
     */
    public function findOneApproved(Entry $entry, string $semver): ?EntryVersion
    {
        return $this->findOneBy(['entry' => $entry, 'semver' => $semver, 'status' => VersionStatus::Approved]);
    }

    /**
     * Ermittelt die höchste SemVer-Version unter allen Versionen eines Eintrags
     * (statusunabhängig) via PHP-seitigem version_compare. Gibt null zurück,
     * wenn noch keine Version existiert.
     */
    public function maxSemver(Entry $entry): ?string
    {
        $semvers = array_column(
            $this->createQueryBuilder('v')->select('v.semver')
                ->andWhere('v.entry = :entry')->setParameter('entry', $entry)
                ->getQuery()->getArrayResult(),
            'semver',
        );
        if ($semvers === []) {
            return null;
        }
        usort($semvers, static fn (string $a, string $b): int => version_compare($a, $b));

        return end($semvers);
    }
}
