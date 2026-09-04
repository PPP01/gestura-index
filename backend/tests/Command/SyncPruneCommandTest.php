<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Api\SyncContract;
use App\Entity\SyncState;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Die 12-Monats-Aufbewahrung ist der EINZIGE Weg, auf dem Blobs unter einem
 * verlorenen Geheimnis je verschwinden – der Nutzer kann seinen Locator nicht
 * mehr ableiten, also kommt niemand mehr an sie heran.
 */
final class SyncPruneCommandTest extends KernelTestCase
{
    private function tester(): CommandTester
    {
        return new CommandTester((new Application(self::bootKernel()))->find('index:sync:prune'));
    }

    /** 12 Monate ohne Lesen oder Schreiben – dann fällt der Stand. */
    public function testItDeletesStatesUntouchedForTwelveMonths(): void
    {
        $tester = $this->tester();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $alt = new SyncState(SyncContract::locatorHash(str_repeat('a', 43)), '0123456789abcdef0123456789abcdef', 'bQ==', 'cA==');
        $alt->lastAccessAt = new \DateTimeImmutable('-400 days');
        $jung = new SyncState(SyncContract::locatorHash(str_repeat('b', 43)), '0123456789abcdef0123456789abcdef', 'bQ==', 'cA==');
        $jung->lastAccessAt = new \DateTimeImmutable('-10 days');
        $em->persist($alt);
        $em->persist($jung);
        $em->flush();
        $altId = $alt->id;
        $jungId = $jung->id;

        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('1', $tester->getDisplay());
        $em->clear();
        self::assertNull($em->find(SyncState::class, $altId));
        self::assertNotNull($em->find(SyncState::class, $jungId));
    }

    /** Ein Stand knapp innerhalb der Frist bleibt liegen. */
    public function testAStateJustInsideTheWindowSurvives(): void
    {
        $tester = $this->tester();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $state = new SyncState(SyncContract::locatorHash(str_repeat('c', 43)), '0123456789abcdef0123456789abcdef', 'bQ==', 'cA==');
        $state->lastAccessAt = new \DateTimeImmutable('-364 days');
        $em->persist($state);
        $em->flush();
        $id = $state->id;

        $tester->execute([]);

        $em->clear();
        self::assertNotNull($em->find(SyncState::class, $id));
    }

    public function testItRejectsANonPositiveThreshold(): void
    {
        $tester = $this->tester();
        $tester->execute(['days' => '0']);

        self::assertSame(2, $tester->getStatusCode()); // Command::INVALID
    }
}
