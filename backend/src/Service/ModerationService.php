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
use Doctrine\ORM\EntityManagerInterface;

final class ModerationService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EntryRepository $entries,
        private readonly EntryVersionRepository $versions,
        private readonly SubmissionService $submission,
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

    public function rejectEntry(Entry $entry): void
    {
        foreach ($this->versions->findBy(['entry' => $entry, 'status' => VersionStatus::Pending]) as $version) {
            $version->status = VersionStatus::Rejected;
        }
        $entry->status = EntryStatus::Deleted;
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
        $report->entry->touch();
        $this->em->flush();
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
