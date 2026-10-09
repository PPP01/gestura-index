<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * index:maintenance:off – deaktiviert den Sync-Wartungsmodus.
 *
 * Löscht die Flag-Datei. Fehlt sie bereits, ist das kein Fehler.
 */
#[AsCommand(
    name: 'index:maintenance:off',
    description: 'Deaktiviert den Wartungsmodus für /api/v1/sync/*',
)]
final class MaintenanceOffCommand extends Command
{
    public function __construct(
        private readonly string $syncMaintenanceFile,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!is_file($this->syncMaintenanceFile)) {
            $io->success('Wartungsmodus war bereits deaktiviert.');

            return Command::SUCCESS;
        }

        error_clear_last();
        if (!unlink($this->syncMaintenanceFile)) {
            $lastError = error_get_last();
            $io->error(\sprintf(
                'Flag-Datei %s konnte nicht gelöscht werden: %s',
                $this->syncMaintenanceFile,
                $lastError['message'] ?? 'unbekannter Fehler',
            ));

            return Command::FAILURE;
        }

        $io->success('Wartungsmodus deaktiviert.');

        return Command::SUCCESS;
    }
}
