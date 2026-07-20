<?php

declare(strict_types=1);

namespace App\Command;

use App\Enum\EntryStatus;
use App\Enum\VersionStatus;
use App\Repository\EntryRepository;
use App\Repository\EntryVersionRepository;
use App\Service\ModerationService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'index:approve', description: 'Gibt einen wartenden Eintrag oder eine wartende Version frei')]
final class ModerationApproveCommand extends Command
{
    public function __construct(
        private readonly EntryRepository $entries,
        private readonly EntryVersionRepository $versions,
        private readonly ModerationService $moderation,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('formatId', InputArgument::REQUIRED, 'formatId des Eintrags');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $entry = $this->entries->findOneBy(['formatId' => $input->getArgument('formatId')]);
        if ($entry === null) {
            $io->error('Unbekannte formatId');

            return Command::FAILURE;
        }

        if ($entry->status === EntryStatus::Pending) {
            $this->moderation->approveEntry($entry);
            $io->success($entry->formatId . ' veröffentlicht');

            return Command::SUCCESS;
        }

        $pending = $this->versions->findOneBy(['entry' => $entry, 'status' => VersionStatus::Pending]);
        if ($pending !== null) {
            $this->moderation->approveVersion($pending);
            $io->success($entry->formatId . ' ' . $pending->semver . ' freigegeben');

            return Command::SUCCESS;
        }

        $io->warning('Nichts freizugeben');

        return Command::FAILURE;
    }
}
