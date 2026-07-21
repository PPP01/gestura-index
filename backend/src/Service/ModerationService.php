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

/**
 * Kapselt alle Admin-seitigen Statusübergänge für Entries, EntryVersions,
 * Reports und Submitters. Hält folgende Statusmaschinen-Invarianten aufrecht:
 * ein Entry mit Status »pending« hat nie eine gesetzte currentVersion;
 * »published« impliziert currentVersion !== null (Guard in publishEntry()).
 */
final class ModerationService
{
    /**
     * Bindet Repositories, SubmissionService und ScreenshotStorage per Dependency Injection.
     */
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EntryRepository $entries,
        private readonly EntryVersionRepository $versions,
        private readonly SubmissionService $submission,
        private readonly ReportRepository $reports,
        private readonly ScreenshotStorage $screenshots,
    ) {
    }

    /**
     * Genehmigt einen neuen Eintrag aus der Moderations-Warteschlange: setzt die
     * wartende Version auf »approved«, trägt sie als currentVersion ein, erhöht
     * den approvedCount des Submitters und markiert den Entry als »published«.
     * Wirft RuntimeException, wenn keine wartende Version gefunden wird.
     */
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

    /**
     * Lehnt einen Eintrag endgültig ab: alle wartenden Versionen werden auf
     * »rejected« gesetzt, der Entry auf »deleted« und der Screenshot entfernt.
     */
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

    /**
     * Gibt eine einzelne Version frei: setzt ihren Status auf »approved«, trägt sie
     * als currentVersion des Entries ein und aktualisiert abgeleitete Felder
     * (Domains, Tags) via SubmissionService.
     */
    public function approveVersion(EntryVersion $version): void
    {
        $version->status = VersionStatus::Approved;
        $entry = $version->entry;
        // currentVersion darf nur vorwärts wandern: eine ältere, noch in der
        // Queue liegende (Transform-)Version wird zwar freigegeben, ersetzt aber
        // keine bereits veröffentlichte neuere Version. Ohne diesen Guard könnte
        // die Freigabe-Reihenfolge currentVersion zurückstufen.
        if ($entry->currentVersion === null
            || version_compare($version->semver, $entry->currentVersion->semver, '>')) {
            $entry->currentVersion = $version;
            $this->submission->refreshDerived($entry, $version->payload);
        }
        $entry->touch();
        $this->em->flush();
    }

    /**
     * Lehnt eine einzelne Version ab, ohne den Entry-Status zu ändern.
     */
    public function rejectVersion(EntryVersion $version): void
    {
        $version->status = VersionStatus::Rejected;
        $this->em->flush();
    }

    /**
     * Schließt eine Meldung ab und setzt den Entry-Status entsprechend.
     * Bei publish=true werden zusätzlich alle offenen Meldungen desselben Eintrags
     * aufgelöst, damit ein erneuter einzelner Report nicht sofort wieder den
     * Hide-Threshold erreicht. Bei publish=false wird der Screenshot entfernt.
     */
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
    /**
     * Setzt alle offenen Meldungen des Eintrags auf »resolved«.
     */
    private function resolveOpenReports(Entry $entry): void
    {
        foreach ($this->reports->findBy(['entry' => $entry, 'status' => ReportStatus::Open]) as $open) {
            $open->status = ReportStatus::Resolved;
        }
    }

    /**
     * Sperrt einen Submitter und versteckt alle seine nicht-gelöschten Einträge.
     * Die Wiederveröffentlichung einzelner Einträge erfolgt anschließend manuell
     * per approveEntry() oder resolveReport().
     */
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

    /**
     * Hebt die Sperre eines Submitters auf. Die Einträge bleiben »hidden« –
     * die Wiederveröffentlichung erfolgt je Eintrag separat.
     */
    public function unban(Submitter $submitter): void
    {
        $submitter->banned = false; // Einträge bleiben hidden — Freigabe je Eintrag per index:approve/resolve
        $this->em->flush();
    }
}
