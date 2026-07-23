<?php
declare(strict_types=1);
namespace App\Repository;

use App\Entity\AdminUser;
use App\Entity\WebAuthnCredential;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<WebAuthnCredential> */
class WebAuthnCredentialRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WebAuthnCredential::class);
    }

    public function findOneByCredentialId(string $credentialId): ?WebAuthnCredential
    {
        return $this->findOneBy(['credentialId' => $credentialId]);
    }

    /**
     * Sperrt alle Passkeys eines Nutzers (FOR UPDATE) und liefert deren Anzahl.
     * Serialisiert konkurrierende Entfernungen, damit die »mindestens zwei
     * Passkeys«-Invariante nicht per Race unterschritten werden kann. Muss
     * innerhalb einer Transaktion laufen (PESSIMISTIC_WRITE).
     */
    public function countForUserForUpdate(AdminUser $user): int
    {
        $rows = $this->createQueryBuilder('c')
            ->andWhere('c.adminUser = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getResult();

        return \count($rows);
    }
}
