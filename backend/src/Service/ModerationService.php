<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Entry;
use App\Entity\EntryVersion;
use App\Entity\Report;
use App\Entity\Submitter;
use App\Enum\EntryStatus;
use App\Enum\ReportStatus;
use App\Enum\VersionStatus;
use App\Repository\EntryRepository;
use App\Repository\EntryVersionRepository;
use App\Repository\ReportRepository;
use Doctrine\ORM\EntityManagerInterface;

final class ModerationService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EntryRepository $entries,
        private readonly EntryVersionRepository $versions,
        private readonly SubmissionService $submission,
        private readonly ReportRepository $reports,
        private readonly ScreenshotStorage $screenshots,
    ) {
    }

    public function approveEntry(Entry $entry): void
    {
        $pending = $this->versions->findOneBy(['entry' => $entry, 'status' => VersionStatus::Pending])
            ?? throw new \RuntimeException('Keine wartende Version für ' . $entry->formatId);
        $this->approveVersion($pending);
        $entry->status = EntryStatus::Published;
        ++$entry->submitter->approvedCount;
        $entry->touch();
        $this->em->flush();
    }

    /**
     * Veröffentlicht einen hidden-Eintrag ohne wartende Version (Seitenpfad
     * von index:approve nach Ban/Auto-Hide durch Meldungen). Ohne den Guard
     * gegen fehlende currentVersion ließe sich ein Eintrag veröffentlichen,
     * der nie eine freigegebene Version hatte.
     */
    public function publishEntry(Entry $entry): void
    {
        if ($entry->currentVersion === null) {
            throw new \RuntimeException('Eintrag hat keine freigegebene Version — erst Version freigeben');
        }
        $entry->status = EntryStatus::Published;
        $this->resolveOpenReports($entry);
        $entry->touch();
        $this->em->flush();
    }

    public function rejectEntry(Entry $entry): void
    {
        foreach ($this->versions->findBy(['entry' => $entry, 'status' => VersionStatus::Pending]) as $version) {
            $version->status = VersionStatus::Rejected;
        }
        $entry->status = EntryStatus::Deleted;
        $this->screenshots->remove($entry);
        $entry->touch();
        $this->em->flush();
    }

    public function approveVersion(EntryVersion $version): void
    {
        $version->status = VersionStatus::Approved;
        $entry = $version->entry;
        $entry->currentVersion = $version;
        $this->submission->refreshDerived($entry, $version->payload);
        $entry->touch();
        $this->em->flush();
    }

    public function rejectVersion(EntryVersion $version): void
    {
        $version->status = VersionStatus::Rejected;
        $this->em->flush();
    }

    public function resolveReport(Report $report, bool $publish): void
    {
        $report->status = ReportStatus::Resolved;
        $report->entry->status = $publish ? EntryStatus::Published : EntryStatus::Deleted;
        if (!$publish) {
            $this->screenshots->remove($report->entry);
        }
        $report->entry->touch();

        if ($publish) {
            $this->resolveOpenReports($report->entry);
        }

        $this->em->flush();
    }

    // Ohne dies würde die nächste einzelne neue Meldung sofort wieder den
    // Hide-Threshold erreichen, weil die übrigen offenen Meldungen desselben
    // Vorfalls weiter mitzählen — gilt für resolveReport(publish) genauso
    // wie für den hidden-Branch von index:approve (publishEntry).
    private function resolveOpenReports(Entry $entry): void
    {
        foreach ($this->reports->findBy(['entry' => $entry, 'status' => ReportStatus::Open]) as $open) {
            $open->status = ReportStatus::Resolved;
        }
    }

    public function ban(Submitter $submitter): void
    {
        $submitter->banned = true;
        foreach ($this->entries->findBy(['submitter' => $submitter]) as $entry) {
            if ($entry->status !== EntryStatus::Deleted) {
                $entry->status = EntryStatus::Hidden;
                $entry->touch();
            }
        }
        $this->em->flush();
    }

    public function unban(Submitter $submitter): void
    {
        $submitter->banned = false; // Einträge bleiben hidden — Freigabe je Eintrag per index:approve/resolve
        $this->em->flush();
    }
}
