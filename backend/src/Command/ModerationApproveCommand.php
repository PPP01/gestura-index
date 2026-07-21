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

/**
 * Konsolen-Command `index:approve` – gibt einen wartenden Entry oder eine wartende
 * EntryVersion im Moderations-Workflow frei; behandelt zusätzlich den Unban-Pfad
 * (hidden → published), wenn keine weitere Version in der Queue liegt.
 */
#[AsCommand(name: 'index:approve', description: 'Gibt einen wartenden Eintrag oder eine wartende Version frei')]
final class ModerationApproveCommand extends Command
{
    /**
     * Nimmt Entry-Repositories und ModerationService per Dependency Injection entgegen.
     */
    public function __construct(
        private readonly EntryRepository $entries,
        private readonly EntryVersionRepository $versions,
        private readonly ModerationService $moderation,
    ) {
        parent::__construct();
    }

    /**
     * Registriert das Pflichtargument `formatId` (reverse-domain-ID des Eintrags).
     */
    protected function configure(): void
    {
        $this->addArgument('formatId', InputArgument::REQUIRED, 'formatId des Eintrags');
    }

    /**
     * Führt die Freigabe durch: pending Entry → approveEntry(), pending Version →
     * approveVersion(), hidden Entry ohne pending Version → publishEntry() (Unban-Pfad).
     * Gibt Command::SUCCESS (0) zurück; Command::FAILURE (1) wenn die formatId unbekannt
     * ist, ModerationService eine RuntimeException wirft oder nichts freizugeben ist.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $entry = $this->entries->findOneBy(['formatId' => $input->getArgument('formatId')]);
        if ($entry === null) {
            $io->error('Unbekannte formatId');

            return Command::FAILURE;
        }

        if ($entry->status === EntryStatus::Pending) {
            try {
                $this->moderation->approveEntry($entry);
            } catch (\RuntimeException $e) {
                $io->error($e->getMessage());

                return Command::FAILURE;
            }
            $io->success($entry->formatId . ' veröffentlicht');

            return Command::SUCCESS;
        }

        $pending = $this->versions->findOneBy(['entry' => $entry, 'status' => VersionStatus::Pending]);
        if ($pending !== null) {
            $this->moderation->approveVersion($pending);
            $io->success($entry->formatId . ' ' . $pending->semver . ' freigegeben');

            return Command::SUCCESS;
        }

        // Ban ohne pending-Version: die Unban-Meldung verspricht die
        // Freigabe per index:approve — ohne diesen Zweig gäbe es dafür
        // keinen Weg (die zuvor genehmigte currentVersion bleibt gültig).
        if ($entry->status === EntryStatus::Hidden) {
            try {
                $this->moderation->publishEntry($entry);
            } catch (\RuntimeException $e) {
                $io->error($e->getMessage());

                return Command::FAILURE;
            }
            $io->success($entry->formatId . ' veröffentlicht');

            return Command::SUCCESS;
        }

        $io->warning('Nichts freizugeben');

        return Command::FAILURE;
    }
}
