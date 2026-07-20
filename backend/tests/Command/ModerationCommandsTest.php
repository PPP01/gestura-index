<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\Entry;
use App\Entity\EntryVersion;
use App\Entity\Submitter;
use App\Enum\Category;
use App\Enum\EntryStatus;
use App\Enum\EntryType;
use App\Enum\VersionStatus;
use App\Service\PayloadAnalyzer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class ModerationCommandsTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Application $console;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->console = new Application(self::$kernel);
    }

    private function createPendingEntry(string $formatId = 'com.example.neu'): Entry
    {
        $submitter = new Submitter(bin2hex(random_bytes(8)), 'hash');
        $entry = new Entry($formatId, EntryType::Menu, $submitter);
        $entry->setCategories([Category::Other]);
        $payload = ['gesturaMenu' => 1, 'id' => $formatId, 'version' => '1.0.0', 'name' => 'Neu',
            'items' => [['id' => 'a', 'label' => 'A', 'action' => 'newTab']]];
        $version = new EntryVersion($entry, '1.0.0', $payload, (new PayloadAnalyzer())->contentHash($payload));
        $this->em->persist($submitter);
        $this->em->persist($entry);
        $this->em->persist($version);
        $this->em->flush();

        return $entry;
    }

    private function runCommand(string $name, array $input = []): CommandTester
    {
        $tester = new CommandTester($this->console->find($name));
        $tester->execute($input);

        return $tester;
    }

    public function testQueueListsPendingEntries(): void
    {
        $this->createPendingEntry();

        $tester = $this->runCommand('index:queue');

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('com.example.neu', $tester->getDisplay());
    }

    public function testApprovePublishesEntryAndCountsApproval(): void
    {
        $entry = $this->createPendingEntry();

        $tester = $this->runCommand('index:approve', ['formatId' => 'com.example.neu']);

        $tester->assertCommandIsSuccessful();
        $this->em->clear();
        $entry = $this->em->getRepository(Entry::class)->findOneBy(['formatId' => 'com.example.neu']);
        self::assertSame(EntryStatus::Published, $entry->status);
        self::assertSame(VersionStatus::Approved, $entry->currentVersion->status);
        self::assertSame('1.0.0', $entry->currentVersion->semver);
        self::assertSame(1, $entry->submitter->approvedCount);
        self::assertNotSame('', $entry->searchText);
    }

    public function testApproveHandlesMissingPendingVersionGracefully(): void
    {
        $entry = $this->createPendingEntry();
        $version = $this->em->getRepository(EntryVersion::class)->findOneBy(['entry' => $entry]);
        $version->status = VersionStatus::Rejected;
        $this->em->flush();

        $tester = $this->runCommand('index:approve', ['formatId' => 'com.example.neu']);

        self::assertNotSame(0, $tester->getStatusCode());
        self::assertStringContainsString('Keine wartende Version für com.example.neu', $tester->getDisplay());
    }

    public function testApprovePublishesHiddenEntryWithoutPendingVersion(): void
    {
        $entry = $this->createPendingEntry();
        $version = $this->em->getRepository(EntryVersion::class)->findOneBy(['entry' => $entry]);
        $version->status = VersionStatus::Approved;
        $entry->currentVersion = $version;
        $entry->status = EntryStatus::Hidden;
        $this->em->flush();

        $tester = $this->runCommand('index:approve', ['formatId' => 'com.example.neu']);

        $tester->assertCommandIsSuccessful();
        $this->em->clear();
        $entry = $this->em->getRepository(Entry::class)->findOneBy(['formatId' => 'com.example.neu']);
        self::assertSame(EntryStatus::Published, $entry->status);
    }

    public function testRejectDeletesEntry(): void
    {
        $this->createPendingEntry();

        $this->runCommand('index:reject', ['formatId' => 'com.example.neu'])->assertCommandIsSuccessful();

        $this->em->clear();
        $entry = $this->em->getRepository(Entry::class)->findOneBy(['formatId' => 'com.example.neu']);
        self::assertSame(EntryStatus::Deleted, $entry->status);
    }

    public function testBanHidesAllEntriesOfSubmitter(): void
    {
        $entry = $this->createPendingEntry();
        $entry->status = EntryStatus::Published;
        $this->em->flush();
        $submitterId = $entry->submitter->id;

        $this->runCommand('index:ban', ['submitterId' => (string) $submitterId])->assertCommandIsSuccessful();

        $this->em->clear();
        $entry = $this->em->getRepository(Entry::class)->findOneBy(['formatId' => 'com.example.neu']);
        self::assertTrue($entry->submitter->banned);
        self::assertSame(EntryStatus::Hidden, $entry->status);
    }
}
