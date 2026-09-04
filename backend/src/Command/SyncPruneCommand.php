<?php

declare(strict_types=1);

namespace App\Command;

use App\Api\SyncContract;
use App\Repository\SyncStateRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * `index:sync:prune` – löscht Sync-Stände, die 12 Monate weder gelesen noch
 * geschrieben wurden (Vertrag, »Retention«).
 *
 * Das ist der EINZIGE Weg, auf dem Blobs unter einem verlorenen Geheimnis je
 * verschwinden: der Nutzer kann seinen Locator nicht mehr ableiten, also
 * kommt niemand mehr an sie heran – auch der Betreiber nicht, der nur den
 * Hash sieht. Die Frist steht so in der PRIVACY.md der Extension und wird dem
 * Nutzer angezeigt; sie hier zu verkürzen wäre ein Vertragsbruch.
 *
 * Gehört als Cronjob auf die Zielumgebung (dort heißt das CLI-Binary php85).
 */
#[AsCommand(name: 'index:sync:prune', description: 'Löscht Sync-Stände, die länger als N Tage unberührt sind')]
final class SyncPruneCommand extends Command
{
    public function __construct(
        private readonly SyncStateRepository $states,
    ) {
        parent::__construct();
    }

    /** Registriert das optionale Argument `days` (Default: die Vertragsfrist). */
    protected function configure(): void
    {
        $this->addArgument('days', InputArgument::OPTIONAL, 'Aufbewahrungsfrist in Tagen', (string) SyncContract::RETENTION_DAYS);
    }

    /**
     * Validiert `days` (positive Ganzzahl, sonst Command::INVALID) und löscht
     * über ein einziges DQL-DELETE alle Stände, deren lastAccessAt vor dem
     * Stichtag liegt.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $days = filter_var($input->getArgument('days'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        if ($days === false) {
            $io->error('Die Aufbewahrungsfrist muss eine positive Ganzzahl sein.');

            return Command::INVALID;
        }

        $deleted = $this->states->deleteUnusedBefore(new \DateTimeImmutable(sprintf('-%d days', $days)));

        $io->success(sprintf('%d Sync-Stände gelöscht (unberührt seit mehr als %d Tagen).', $deleted, $days));

        return Command::SUCCESS;
    }
}
