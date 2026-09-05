<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Api\SyncContract;
use App\Entity\SyncState;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
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

    /** Legt einen Stand mit dem angegebenen Alter an; liefert seine id. */
    private function seedAged(string $locator, string $age): int
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $state = new SyncState(SyncContract::locatorHash($locator), '0123456789abcdef0123456789abcdef', 'bQ==', 'cA==');
        $state->lastAccessAt = new \DateTimeImmutable($age);
        $em->persist($state);
        $em->flush();

        return (int) $state->id;
    }

    /** 12 Monate ohne Lesen oder Schreiben – dann fällt der Stand. */
    public function testItDeletesStatesUntouchedForTwelveMonths(): void
    {
        $tester = $this->tester();
        $altId = $this->seedAged(str_repeat('a', 43), '-400 days');
        $jungId = $this->seedAged(str_repeat('b', 43), '-10 days');

        $tester->execute([]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('1', $tester->getDisplay());
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        self::assertNull($em->find(SyncState::class, $altId));
        self::assertNotNull($em->find(SyncState::class, $jungId));
    }

    /** Ein Stand knapp innerhalb der Frist bleibt liegen. */
    public function testAStateJustInsideTheWindowSurvives(): void
    {
        $tester = $this->tester();
        $id = $this->seedAged(str_repeat('c', 43), '-364 days');

        $tester->execute([]);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        self::assertNotNull($em->find(SyncState::class, $id));
    }

    public function testItRejectsANonPositiveThreshold(): void
    {
        $tester = $this->tester();
        $tester->execute(['days' => '0']);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
    }
}
