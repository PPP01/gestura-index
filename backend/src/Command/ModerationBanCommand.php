<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\SubmitterRepository;
use App\Service\ModerationService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Konsolen-Command `index:ban` – sperrt einen Submitter (alle seine Einträge werden
 * auf hidden gesetzt) oder hebt die Sperre per `--unban` auf; nach einem Unban müssen
 * einzelne Einträge manuell per `index:approve` wieder freigegeben werden.
 */
#[AsCommand(name: 'index:ban', description: 'Sperrt einen Submitter (alle Einträge → hidden) oder hebt die Sperre auf')]
final class ModerationBanCommand extends Command
{
    /**
     * Nimmt SubmitterRepository und ModerationService per Dependency Injection entgegen.
     */
    public function __construct(
        private readonly SubmitterRepository $submitters,
        private readonly ModerationService $moderation,
    ) {
        parent::__construct();
    }

    /**
     * Registriert das Pflichtargument `submitterId` (numerische DB-ID) und die
     * Option `--unban` zum Aufheben einer bestehenden Sperre.
     */
    protected function configure(): void
    {
        $this->addArgument('submitterId', InputArgument::REQUIRED, 'ID des Submitters');
        $this->addOption('unban', null, InputOption::VALUE_NONE, 'Sperre aufheben');
    }

    /**
     * Sperrt den Submitter und versteckt alle seine Einträge, oder hebt die Sperre auf
     * (Einträge bleiben hidden – je Eintrag per `index:approve` erneut freigeben).
     * Gibt Command::FAILURE (1) zurück, wenn die Submitter-ID unbekannt ist.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $submitter = $this->submitters->find((int) $input->getArgument('submitterId'));
        if ($submitter === null) {
            $io->error('Unbekannter Submitter');

            return Command::FAILURE;
        }

        if ($input->getOption('unban')) {
            $this->moderation->unban($submitter);
            $io->success('Sperre aufgehoben (Einträge bleiben hidden — je Eintrag per index:approve freigeben)');
        } else {
            $this->moderation->ban($submitter);
            $io->success('Submitter gesperrt, alle Einträge versteckt');
        }

        return Command::SUCCESS;
    }
}
