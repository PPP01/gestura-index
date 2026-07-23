<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\Account;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class AccountPruneCommandTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Application $console;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->console = new Application(self::$kernel);
    }

    public function testPruneRemovesOnlyStaleAccounts(): void
    {
        $fresh = new Account(bin2hex(random_bytes(8)), 'hash');
        $stale = new Account(bin2hex(random_bytes(8)), 'hash');
        $stale->lastSeenAt = new \DateTimeImmutable('-400 days');
        $this->em->persist($fresh);
        $this->em->persist($stale);
        $this->em->flush();
        $freshSelector = $fresh->tokenSelector;

        $command = $this->console->find('index:account:prune');
        $tester = new CommandTester($command);
        $tester->execute(['days' => '365']);
        $tester->assertCommandIsSuccessful();

        $this->em->clear();
        $repo = $this->em->getRepository(Account::class);
        self::assertSame(1, $repo->count([]));
        self::assertNotNull($repo->findOneBy(['tokenSelector' => $freshSelector]));
    }
}
