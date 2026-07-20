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

#[AsCommand(name: 'index:reports', description: 'Zeigt Meldungen (Standard: nur offene)')]
final class ModerationReportsCommand extends Command
{
    public function __construct(private readonly ReportRepository $reports)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('all', null, InputOption::VALUE_NONE, 'Auch erledigte Meldungen anzeigen');
    }

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
