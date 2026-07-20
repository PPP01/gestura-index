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

#[AsCommand(name: 'index:ban', description: 'Sperrt einen Submitter (alle Einträge → hidden) oder hebt die Sperre auf')]
final class ModerationBanCommand extends Command
{
    public function __construct(
        private readonly SubmitterRepository $submitters,
        private readonly ModerationService $moderation,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('submitterId', InputArgument::REQUIRED, 'ID des Submitters');
        $this->addOption('unban', null, InputOption::VALUE_NONE, 'Sperre aufheben');
    }

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
