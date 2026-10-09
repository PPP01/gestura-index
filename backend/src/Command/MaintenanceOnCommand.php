<?php

declare(strict_types=1);

namespace App\Command;

use App\Api\SyncContract;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * index:maintenance:on – aktiviert den Sync-Wartungsmodus.
 *
 * Schreibt die Flag-Datei, die SyncMaintenanceSubscriber ausliest. Optional
 * mit --until (ISO-8601), damit die Extension "bis ..." anzeigen kann.
 * Ein until in der Vergangenheit wird mit INVALID abgelehnt.
 */
#[AsCommand(
    name: 'index:maintenance:on',
    description: 'Aktiviert den Wartungsmodus für /api/v1/sync/*',
)]
final class MaintenanceOnCommand extends Command
{
    public function __construct(
        private readonly string $syncMaintenanceFile,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'until',
            null,
            InputOption::VALUE_REQUIRED,
            'Voraussichtliches Ende (ISO-8601, z. B. 2026-10-09T14:00:00+00:00)',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $until = $input->getOption('until');
        $content = '';

        if ($until !== null) {
            $dt = SyncContract::parseAtomTimestamp($until);
            if ($dt === null) {
                $io->error(\sprintf(
                    '»%s« ist kein gültiges ISO-8601-Datum mit Offset.',
                    $until,
                ));

                return Command::INVALID;
            }

            if ($dt <= new \DateTimeImmutable()) {
                $io->error(\sprintf(
                    'Das angegebene Datum »%s« liegt in der'
                    . ' Vergangenheit.',
                    $until,
                ));

                return Command::INVALID;
            }

            // In das kanonische UTC-Format normieren.
            $content = SyncContract::formatTimestamp($dt);
        }

        $dir = \dirname($this->syncMaintenanceFile);
        if (!is_dir($dir) && !mkdir($dir, 0o755, true)) {
            $io->error(\sprintf(
                'Verzeichnis %s konnte nicht angelegt werden.',
                $dir,
            ));

            return Command::FAILURE;
        }

        if (file_put_contents($this->syncMaintenanceFile, $content) === false) {
            $io->error(\sprintf(
                'Flag-Datei %s konnte nicht geschrieben werden.',
                $this->syncMaintenanceFile,
            ));

            return Command::FAILURE;
        }

        if ($content !== '') {
            $io->success(\sprintf('Wartungsmodus aktiviert (bis %s).', $content));
        } else {
            $io->success('Wartungsmodus aktiviert (ohne Zeitangabe).');
        }

        return Command::SUCCESS;
    }
}
