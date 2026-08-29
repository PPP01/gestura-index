<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PageSetting;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PageSetting>
 */
class PageSettingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PageSetting::class);
    }

    /** @return array<string,bool> pageKey => enabled */
    public function findAllIndexed(): array
    {
        $out = [];
        foreach ($this->findAll() as $ps) {
            $out[$ps->pageKey] = $ps->enabled;
        }

        return $out;
    }

    public function findByKey(string $key): ?PageSetting
    {
        return $this->findOneBy(['pageKey' => $key]);
    }
}
