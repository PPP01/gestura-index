<?php

declare(strict_types=1);

namespace App\Command;

use App\Enum\ReportStatus;
use App\Repository\ReportRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Konsolen-Command `index:reports` – listet Nutzer-Meldungen als Tabelle;
 * standardmäßig nur offene Meldungen, mit `--all` auch bereits erledigte.
 */
#[AsCommand(name: 'index:reports', description: 'Zeigt Meldungen (Standard: nur offene)')]
final class ModerationReportsCommand extends Command
{
    /**
     * Nimmt das ReportRepository per Dependency Injection entgegen.
     */
    public function __construct(private readonly ReportRepository $reports)
    {
        parent::__construct();
    }

    /**
     * Registriert die Option `--all`, um auch erledigte Meldungen anzuzeigen.
     */
    protected function configure(): void
    {
        $this->addOption('all', null, InputOption::VALUE_NONE, 'Auch erledigte Meldungen anzeigen');
    }

    /**
     * Lädt Meldungen nach Status-Filter (offen oder alle), kürzt lange Kommentare auf
     * 60 Zeichen und gibt sie als Tabelle aus. Gibt stets Command::SUCCESS (0) zurück.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $criteria = $input->getOption('all') ? [] : ['status' => ReportStatus::Open];

        $rows = [];
        foreach ($this->reports->findBy($criteria, ['createdAt' => 'ASC']) as $report) {
            $rows[] = [$report->id, $report->entry->formatId, $report->reason->value, $report->status->value,
                mb_strimwidth((string) $report->comment, 0, 60, '…'), $report->createdAt->format('Y-m-d H:i')];
        }
        $rows === [] ? $io->text('Keine Meldungen') : $io->table(['ID', 'formatId', 'Grund', 'Status', 'Kommentar', 'gemeldet'], $rows);

        return Command::SUCCESS;
    }
}
