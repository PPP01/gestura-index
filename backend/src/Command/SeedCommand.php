<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Entry;
use App\Entity\EntryVersion;
use App\Entity\Submitter;
use App\Enum\EntryStatus;
use App\Enum\VersionStatus;
use App\Repository\EntryRepository;
use App\Service\PayloadAnalyzer;
use App\Service\Seed\SeedCatalog;
use App\Service\SubmissionService;
use App\Service\ExchangeValidator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Spielt einen kuratierten Basisstock veröffentlichter Einträge ein
 * (`SeedCatalog`) – Suchmaschinen und Website-Menüs mit echten URLs.
 *
 * Idempotent: bereits vorhandene formatIds werden übersprungen, ein erneuter
 * Lauf legt keine Duplikate an. `--purge` entfernt alle Seed-Einträge wieder
 * (erkennbar am synthetischen Seed-Submitter). Jeder Payload wird VOR dem
 * Schreiben über denselben ExchangeValidator geprüft wie eine echte
 * Einreichung – fehlerhafte Katalog-Einträge werden gemeldet, nicht geschrieben.
 */
#[AsCommand(name: 'index:seed', description: 'Füllt den Index mit einem kuratierten Basisstock (Suchmaschinen + Menüs).')]
final class SeedCommand extends Command
{
    /** Fester 16-Zeichen-Selector des synthetischen Seed-Submitters (unique). */
    private const SEED_SELECTOR = 'seedcli000000000';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EntryRepository $entries,
        private readonly SeedCatalog $catalog,
        private readonly PayloadAnalyzer $analyzer,
        private readonly SubmissionService $submission,
        private readonly ExchangeValidator $validator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('purge', null, InputOption::VALUE_NONE, 'Entfernt alle Seed-Einträge, statt welche anzulegen.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($input->getOption('purge')) {
            $removed = $this->purge();
            $this->em->flush();
            $io->success(sprintf('%d Seed-Einträge entfernt.', $removed));

            return Command::SUCCESS;
        }

        $submitter = $this->seedSubmitter();
        $created = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($this->catalog->entries() as $seed) {
            if ($this->entries->findOneBy(['formatId' => $seed->formatId]) !== null) {
                ++$skipped;
                continue;
            }

            // Belt-and-suspenders: derselbe Validator wie bei echten Einreichungen.
            $json = json_encode($seed->payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $result = $this->validator->validate($json);
            if (!$result->ok) {
                $io->warning(sprintf('%s: ungültig (%s)', $seed->formatId, implode(', ', $result->errors)));
                ++$failed;
                continue;
            }

            $entry = new Entry($seed->formatId, $seed->type, $submitter);
            $version = new EntryVersion($entry, $seed->semver, $seed->payload, $this->analyzer->contentHash($seed->payload));
            $version->status = VersionStatus::Approved;
            $version->hasTransformCode = $this->analyzer->hasTransform($seed->payload);

            $this->em->persist($entry);
            $this->em->persist($version);

            $entry->currentVersion = $version;
            $entry->status = EntryStatus::Published;
            $this->submission->applyMetadata($entry, [
                'categories' => $seed->categories,
                'tags' => $seed->tags,
                'deprecated' => null,
                'successorFormatId' => false,
            ]);
            $this->submission->refreshDerived($entry, $seed->payload);
            ++$created;
        }

        $this->em->flush();
        $io->success(sprintf('%d Einträge angelegt, %d übersprungen (bereits vorhanden), %d ungültig.', $created, $skipped, $failed));

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /** Findet oder erstellt den synthetischen Seed-Submitter (nicht token-editierbar). */
    private function seedSubmitter(): Submitter
    {
        $existing = $this->em->getRepository(Submitter::class)->findOneBy(['tokenSelector' => self::SEED_SELECTOR]);
        if ($existing instanceof Submitter) {
            return $existing;
        }

        $submitter = new Submitter(self::SEED_SELECTOR, 'seed:no-editable-token');
        $submitter->approvedCount = 9999;
        $this->em->persist($submitter);

        return $submitter;
    }

    /**
     * Entfernt alle Einträge des Seed-Submitters. Abhängige EntryVersions werden
     * explizit per ORM entfernt (die Identity-Map-Falle aus lessons.md), die
     * currentVersion-Referenz vorher gelöst.
     */
    private function purge(): int
    {
        $submitter = $this->em->getRepository(Submitter::class)->findOneBy(['tokenSelector' => self::SEED_SELECTOR]);
        if (!$submitter instanceof Submitter) {
            return 0;
        }

        $versionRepo = $this->em->getRepository(EntryVersion::class);
        $removed = 0;
        foreach ($this->entries->findBy(['submitter' => $submitter]) as $entry) {
            $entry->currentVersion = null;
            foreach ($versionRepo->findBy(['entry' => $entry]) as $version) {
                $this->em->remove($version);
            }
            $this->em->remove($entry);
            ++$removed;
        }
        $this->em->flush();
        $this->em->remove($submitter);

        return $removed;
    }
}
