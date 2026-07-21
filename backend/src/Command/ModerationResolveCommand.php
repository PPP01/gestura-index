<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\ReportRepository;
use App\Service\ModerationService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Konsolen-Command `index:resolve` – erledigt eine offene Meldung und führt die
 * gewählte Aktion am gemeldeten Entry aus: `publish` (Entry wieder sichtbar schalten)
 * oder `delete` (Entry dauerhaft entfernen).
 */
#[AsCommand(name: 'index:resolve', description: 'Erledigt eine Meldung: Eintrag wieder veröffentlichen oder löschen')]
final class ModerationResolveCommand extends Command
{
    /**
     * Nimmt ReportRepository und ModerationService per Dependency Injection entgegen.
     */
    public function __construct(
        private readonly ReportRepository $reports,
        private readonly ModerationService $moderation,
    ) {
        parent::__construct();
    }

    /**
     * Registriert das Pflichtargument `reportId` (numerische DB-ID der Meldung)
     * und die Option `--action` mit den erlaubten Werten `publish` oder `delete`.
     */
    protected function configure(): void
    {
        $this->addArgument('reportId', InputArgument::REQUIRED, 'ID der Meldung');
        $this->addOption('action', null, InputOption::VALUE_REQUIRED, 'publish oder delete');
    }

    /**
     * Sucht die Meldung, validiert die --action-Option (publish|delete) und delegiert
     * an ModerationService::resolveReport(). Gibt Command::FAILURE (1) zurück, wenn
     * die reportId unbekannt ist oder --action fehlt bzw. ungültig ist.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $report = $this->reports->find((int) $input->getArgument('reportId'));
        $action = $input->getOption('action');
        if ($report === null || !\in_array($action, ['publish', 'delete'], true)) {
            $io->error('Unbekannte Meldung oder --action fehlt (publish|delete)');

            return Command::FAILURE;
        }

        $this->moderation->resolveReport($report, $action === 'publish');
        $io->success('Meldung erledigt, Eintrag ' . $report->entry->formatId . ' → ' . $report->entry->status->value);

        return Command::SUCCESS;
    }
}
