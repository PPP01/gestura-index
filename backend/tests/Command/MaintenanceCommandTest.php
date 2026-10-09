<?php

declare(strict_types=1);

namespace App\Tests\Command;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * CLI-Kommandos zum Aktivieren/Deaktivieren des Sync-Wartungsmodus.
 */
final class MaintenanceCommandTest extends KernelTestCase
{
    private string $flagFile;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->flagFile = static::getContainer()
            ->getParameter('app.sync_maintenance_file');
        if (is_file($this->flagFile)) {
            unlink($this->flagFile);
        }
    }

    protected function tearDown(): void
    {
        if (is_file($this->flagFile)) {
            @unlink($this->flagFile);
        }
        parent::tearDown();
    }

    private function on(): CommandTester
    {
        return new CommandTester(
            (new Application(self::$kernel))->find('index:maintenance:on'),
        );
    }

    private function off(): CommandTester
    {
        return new CommandTester(
            (new Application(self::$kernel))->find('index:maintenance:off'),
        );
    }

    public function testOnCreatesFlagFile(): void
    {
        $tester = $this->on();
        $tester->execute([]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertFileExists($this->flagFile);
        self::assertSame('', file_get_contents($this->flagFile));
    }

    public function testOnWithUntilWritesNormalizedTimestamp(): void
    {
        $tester = $this->on();
        $tester->execute(['--until' => '2026-12-31T14:00:00+02:00']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        // Normiert auf UTC:
        self::assertSame(
            '2026-12-31T12:00:00+00:00',
            trim((string) file_get_contents($this->flagFile)),
        );
    }

    public function testOnRejectsInvalidUntil(): void
    {
        $tester = $this->on();
        $tester->execute(['--until' => 'kein-datum']);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertFileDoesNotExist($this->flagFile);
    }

    /** "tomorrow" ist kein ATOM – wird abgelehnt (Terra M1). */
    public function testOnRejectsTomorrow(): void
    {
        $tester = $this->on();
        $tester->execute(['--until' => 'tomorrow']);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertFileDoesNotExist($this->flagFile);
    }

    /** ATOM ohne Offset wird abgelehnt (Terra M1). */
    public function testOnRejectsMissingOffset(): void
    {
        $tester = $this->on();
        $tester->execute(['--until' => '2026-12-31T14:00:00']);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertFileDoesNotExist($this->flagFile);
    }

    /** Unmögliches Datum (Feb 31) wird abgelehnt (Terra M1). */
    public function testOnRejectsImpossibleDate(): void
    {
        $tester = $this->on();
        $tester->execute(['--until' => '2026-02-31T12:00:00+00:00']);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertFileDoesNotExist($this->flagFile);
    }

    /** E-A: until in der Vergangenheit wird abgelehnt. */
    public function testOnRejectsPastUntil(): void
    {
        $past = (new \DateTimeImmutable('-1 hour'))
            ->format(\DateTimeInterface::ATOM);
        $tester = $this->on();
        $tester->execute(['--until' => $past]);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertFileDoesNotExist($this->flagFile);
    }

    public function testOffDeletesFlagFile(): void
    {
        // Erst anlegen ...
        $this->on()->execute([]);
        self::assertFileExists($this->flagFile);

        // ... dann entfernen.
        $tester = $this->off();
        $tester->execute([]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertFileDoesNotExist($this->flagFile);
    }

    public function testOffWhenAlreadyOffIsSuccess(): void
    {
        $tester = $this->off();
        $tester->execute([]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
    }

    /**
     * Gemini N-5: Der Fehlerpfad (unlink scheitert → Command::FAILURE)
     * ist bewusst nicht getestet. is_file() als Guard lässt nur echte
     * reguläre Dateien durch; unlink() kann danach nur bei einem
     * Rechte-Entzug zwischen den Aufrufen oder einem Read-only-FS
     * scheitern. Ein Rechte-basierter Test wäre als root wirkungslos
     * (CI, Docker), und ein Verzeichnis unter dem Flag-Pfad fällt am
     * is_file()-Guard vorbei. Der Pfad ist durch Code-Review
     * abgedeckt: error_clear_last() + error_get_last() statt @unlink
     * geben die Ursache weiter.
     */
}
