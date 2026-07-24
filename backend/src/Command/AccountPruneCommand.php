<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\AccountRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Konsolen-Command `index:account:prune` – löscht anonyme End-Nutzer-Konten,
 * deren letzte Aktivität (lastSeenAt) länger als die angegebene Frist zurückliegt
 * (Default 365 Tage). Datensparsamkeit. Sobald der Settings-Sync existiert,
 * müssen zugehörige Sync-Blobs hier mitgelöscht werden (dort umzusetzen).
 */
#[AsCommand(name: 'index:account:prune', description: 'Löscht Konten, die länger als N Tage inaktiv sind')]
final class AccountPruneCommand extends Command
{
    public function __construct(
        private readonly AccountRepository $accounts,
    ) {
        parent::__construct();
    }

    /** Registriert das optionale Argument `days` (Default 365). */
    protected function configure(): void
    {
        $this->addArgument('days', InputArgument::OPTIONAL, 'Inaktivitätsschwelle in Tagen', '365');
    }

    /**
     * Validiert `days` (positive Ganzzahl, sonst Command::INVALID) und löscht
     * über ein einziges DQL-DELETE alle Konten, deren lastSeenAt vor dem
     * Stichtag liegt; gibt die Anzahl gelöschter Konten aus.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $days = filter_var($input->getArgument('days'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        if ($days === false) {
            $io->error('Die Inaktivitätsschwelle muss eine positive Ganzzahl sein.');

            return Command::INVALID;
        }

        $cutoff = new \DateTimeImmutable(sprintf('-%d days', $days));
        $deleted = $this->accounts->deleteInactiveBefore($cutoff);

        $io->success(sprintf('%d inaktive Konten gelöscht (älter als %d Tage).', $deleted, $days));

        return Command::SUCCESS;
    }
}
