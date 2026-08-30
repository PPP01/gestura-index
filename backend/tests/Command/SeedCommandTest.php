<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\Entry;
use App\Entity\EntryVersion;
use App\Enum\EntryStatus;
use App\Service\Seed\SeedCatalog;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class SeedCommandTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Application $console;
    private SeedCatalog $catalog;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->console = new Application(self::$kernel);
        $this->catalog = self::getContainer()->get(SeedCatalog::class);
    }

    private function runSeed(array $input = []): CommandTester
    {
        $tester = new CommandTester($this->console->find('index:seed'));
        $tester->execute($input);

        return $tester;
    }

    public function testSeedCreatesPublishedEntriesForTheWholeCatalog(): void
    {
        $expected = \count($this->catalog->entries());

        $tester = $this->runSeed();
        $tester->assertCommandIsSuccessful();

        $this->em->clear();
        $published = $this->em->getRepository(Entry::class)->count(['status' => EntryStatus::Published]);
        self::assertSame($expected, $published, 'Alle Katalog-Einträge sollten veröffentlicht sein');

        // Jeder veröffentlichte Eintrag hat eine currentVersion (Invariante).
        foreach ($this->em->getRepository(Entry::class)->findAll() as $entry) {
            self::assertNotNull($entry->currentVersion, $entry->formatId);
        }
    }

    public function testSeedIsIdempotent(): void
    {
        $this->runSeed()->assertCommandIsSuccessful();
        $this->em->clear();
        $afterFirst = $this->em->getRepository(Entry::class)->count([]);

        $second = $this->runSeed();
        $second->assertCommandIsSuccessful();
        self::assertStringContainsString('übersprungen', $second->getDisplay());

        $this->em->clear();
        self::assertSame($afterFirst, $this->em->getRepository(Entry::class)->count([]), 'Ein zweiter Lauf darf keine Duplikate anlegen');
    }

    public function testPurgeRemovesAllSeedEntriesAndVersions(): void
    {
        $this->runSeed()->assertCommandIsSuccessful();
        $this->em->clear();
        self::assertGreaterThan(0, $this->em->getRepository(Entry::class)->count([]));

        $purge = $this->runSeed(['--purge' => true]);
        $purge->assertCommandIsSuccessful();

        $this->em->clear();
        self::assertSame(0, $this->em->getRepository(Entry::class)->count([]), 'purge muss alle Seed-Einträge entfernen');
        self::assertSame(0, $this->em->getRepository(EntryVersion::class)->count([]), 'purge muss auch die Versionen entfernen');
    }
}
