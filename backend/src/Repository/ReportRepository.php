<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Entry;
use App\Entity\Report;
use App\Enum\ReportStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Datenbankzugriff für Report-Entitäten – stellt Zähl- und
 * Abfragemethoden für Meldungen gegen Einträge bereit.
 *
 * @extends ServiceEntityRepository<Report>
 */
class ReportRepository extends ServiceEntityRepository
{
    /**
     * Registriert den Repository-Service für die Report-Entität im Doctrine-ManagerRegistry.
     */
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Report::class);
    }

    /**
     * Zählt alle offenen (noch nicht bearbeiteten) Meldungen für einen Eintrag.
     * Wird von der Moderationslogik ausgewertet, um Einträge mit vielen Meldungen
     * hervorzuheben.
     */
    public function countOpenFor(Entry $entry): int
    {
        return $this->count(['entry' => $entry, 'status' => ReportStatus::Open]);
    }
}
