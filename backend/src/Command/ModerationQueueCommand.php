<?php

declare(strict_types=1);

namespace App\Command;

use App\Enum\EntryStatus;
use App\Enum\VersionStatus;
use App\Repository\EntryRepository;
use App\Repository\EntryVersionRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Konsolen-Command `index:queue` – gibt einen Überblick über die Moderations-
 * Warteschlange: neue pending Einträge und pending Versionen bereits veröffentlichter
 * Einträge (insbesondere solche mit transformCode).
 */
#[AsCommand(name: 'index:queue', description: 'Zeigt die Moderations-Warteschlange')]
final class ModerationQueueCommand extends Command
{
    /**
     * Nimmt EntryRepository und EntryVersionRepository per Dependency Injection entgegen.
     */
    public function __construct(
        private readonly EntryRepository $entries,
        private readonly EntryVersionRepository $versions,
    ) {
        parent::__construct();
    }

    /**
     * Gibt zwei Sektionen aus: neue Einträge im Status pending (älteste zuerst) und
     * wartende Versionen veröffentlichter Einträge mit transformCode-Hinweis.
     * Gibt stets Command::SUCCESS (0) zurück, auch wenn die Warteschlange leer ist.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->section('Neue Einträge (pending)');
        $rows = [];
        foreach ($this->entries->findBy(['status' => EntryStatus::Pending], ['createdAt' => 'ASC']) as $entry) {
            $rows[] = [$entry->formatId, $entry->type->value, $entry->createdAt->format('Y-m-d H:i')];
        }
        $rows === [] ? $io->text('leer') : $io->table(['formatId', 'Typ', 'eingereicht'], $rows);

        $io->section('Wartende Versionen veröffentlichter Einträge (Transform-Queue)');
        $rows = [];
        foreach ($this->versions->findBy(['status' => VersionStatus::Pending], ['submittedAt' => 'ASC']) as $version) {
            if ($version->entry->status === EntryStatus::Published) {
                $rows[] = [$version->entry->formatId, $version->semver, $version->hasTransformCode ? 'ja' : 'nein', $version->submittedAt->format('Y-m-d H:i')];
            }
        }
        $rows === [] ? $io->text('leer') : $io->table(['formatId', 'Version', 'Skript', 'eingereicht'], $rows);

        return Command::SUCCESS;
    }
}
