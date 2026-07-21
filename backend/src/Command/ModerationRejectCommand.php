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
 * Konsolen-Command `index:reject` – lehnt einen wartenden Entry oder eine wartende
 * EntryVersion ab; ein abgelehnter Erst-Entry wird vollständig gelöscht.
 */
#[AsCommand(name: 'index:reject', description: 'Lehnt einen wartenden Eintrag oder eine wartende Version ab')]
final class ModerationRejectCommand extends Command
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
     * Lehnt ab: pending Entry → rejectEntry() (löscht den Eintrag), pending Version →
     * rejectVersion() (Version wird verworfen, Entry bleibt published).
     * Gibt Command::FAILURE (1) zurück, wenn die formatId unbekannt ist oder nichts
     * abzulehnen ist (Entry bereits published, keine pending Version vorhanden).
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
            $this->moderation->rejectEntry($entry);
            $io->success($entry->formatId . ' abgelehnt (deleted)');

            return Command::SUCCESS;
        }

        $pending = $this->versions->findOneBy(['entry' => $entry, 'status' => VersionStatus::Pending]);
        if ($pending !== null) {
            $this->moderation->rejectVersion($pending);
            $io->success($entry->formatId . ' ' . $pending->semver . ' abgelehnt');

            return Command::SUCCESS;
        }

        $io->warning('Nichts abzulehnen');

        return Command::FAILURE;
    }
}
