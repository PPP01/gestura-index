<?php

declare(strict_types=1);

namespace App\Repository;

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
}
