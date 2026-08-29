<?php

declare(strict_types=1);

namespace App\Tests\PageVisibility;

use App\Entity\PageSetting;
use App\PageVisibility\ToggleablePages;
use App\Repository\PageSettingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PageSettingRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    private function repo(): PageSettingRepository
    {
        return $this->em->getRepository(PageSetting::class);
    }

    public function testFindAllIndexedReturnsSeedsAsEnabled(): void
    {
        $indexed = $this->repo()->findAllIndexed();

        foreach (ToggleablePages::KEYS as $key) {
            self::assertArrayHasKey($key, $indexed);
            self::assertTrue($indexed[$key]);
        }
    }

    public function testDisablingAPageIsReflectedInFindAllIndexed(): void
    {
        $vergleich = $this->repo()->findByKey('vergleich');
        self::assertNotNull($vergleich);

        $vergleich->enabled = false;
        $this->em->flush();
        // Identity-Map leeren, damit findAllIndexed() das Objekt frisch aus
        // der DB lädt statt das bereits im Speicher mutierte zurückzugeben –
        // sonst würde der Test auch bei falschem Mapping/fehlendem Flush grün bleiben.
        $this->em->clear();

        $indexed = $this->repo()->findAllIndexed();
        self::assertFalse($indexed['vergleich']);
    }

    public function testFindByKeyReturnsNullForUnknownKey(): void
    {
        self::assertNull($this->repo()->findByKey('nope'));
    }
}
