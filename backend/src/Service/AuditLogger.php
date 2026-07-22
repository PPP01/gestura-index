<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AdminUser;
use App\Entity\AuditLogEntry;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Persistiert Admin-Aktionen als AuditLogEntry (Nachvollziehbarkeit für
 * destruktive und sicherheitsrelevante Aktionen im Admin-Bereich).
 */
final class AuditLogger
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function log(?AdminUser $actor, string $action, ?string $targetType = null, ?string $targetId = null, ?array $detail = null): void
    {
        $this->em->persist(new AuditLogEntry($actor, $action, $targetType, $targetId, $detail));
        $this->em->flush();
    }
}
