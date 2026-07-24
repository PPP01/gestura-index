<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\Account;
use App\Entity\Entry;
use App\Entity\EntryVersion;
use App\Entity\Rating;
use App\Entity\Submitter;
use App\Enum\CommentStatus;
use App\Enum\EntryStatus;
use App\Enum\EntryType;
use App\Service\PayloadAnalyzer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class CommentModerationTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Application $console;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->console = new Application(self::$kernel);
    }

    private function seedPendingComment(): Rating
    {
        $submitter = new Submitter(bin2hex(random_bytes(8)), 'hash');
        $entry = new Entry('com.example.cmt', EntryType::Menu, $submitter);
        $entry->status = EntryStatus::Published;
        $payload = ['gesturaMenu' => 1, 'id' => 'com.example.cmt', 'version' => '1.0.0', 'name' => 'X',
            'items' => [['id' => 'a', 'label' => 'A', 'action' => 'newTab']]];
        $version = new EntryVersion($entry, '1.0.0', $payload, (new PayloadAnalyzer())->contentHash($payload));
        $account = new Account(bin2hex(random_bytes(8)), 'hash-a');
        $rating = new Rating($account, $entry, 3);
        $rating->comment = 'wartet auf Freigabe';
        $rating->commentStatus = CommentStatus::Pending;

        $this->em->persist($submitter);
        $this->em->persist($entry);
        $this->em->persist($version);
        $this->em->persist($account);
        $this->em->persist($rating);
        $this->em->flush();

        return $rating;
    }

    private function runCommand(string $name, array $input = []): CommandTester
    {
        $tester = new CommandTester($this->console->find($name));
        $tester->execute($input);

        return $tester;
    }

    public function testApproveMakesCommentApproved(): void
    {
        $id = $this->seedPendingComment()->id;

        $tester = $this->runCommand('index:comments:approve', ['id' => (string) $id]);
        self::assertSame(0, $tester->getStatusCode());

        $this->em->clear();
        self::assertSame(CommentStatus::Approved, $this->em->getRepository(Rating::class)->find($id)->commentStatus);
    }

    public function testRejectHidesTextButKeepsStar(): void
    {
        $id = $this->seedPendingComment()->id;

        $tester = $this->runCommand('index:comments:reject', ['id' => (string) $id]);
        self::assertSame(0, $tester->getStatusCode());

        $this->em->clear();
        $reloaded = $this->em->getRepository(Rating::class)->find($id);
        self::assertSame(CommentStatus::Rejected, $reloaded->commentStatus);
        self::assertSame(3, $reloaded->stars); // Stern bleibt
    }

    public function testApproveNonPendingFails(): void
    {
        $id = $this->seedPendingComment()->id;
        $this->runCommand('index:comments:approve', ['id' => (string) $id]); // erste Freigabe

        // Zweite Freigabe: bereits approved → Guard schlägt an (FAILURE):
        $tester = $this->runCommand('index:comments:approve', ['id' => (string) $id]);
        self::assertSame(1, $tester->getStatusCode());
    }
}
