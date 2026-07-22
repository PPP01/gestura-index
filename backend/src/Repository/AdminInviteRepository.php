<?php
declare(strict_types=1);
namespace App\Repository;

use App\Entity\AdminInvite;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<AdminInvite> */
class AdminInviteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AdminInvite::class);
    }

    public function findOneBySelector(string $selector): ?AdminInvite
    {
        return $this->findOneBy(['tokenSelector' => $selector]);
    }
}
