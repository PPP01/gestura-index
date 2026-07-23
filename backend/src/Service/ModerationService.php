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
        // Nur wartende Einträge werden hier freigegeben. Ohne diesen Guard ließe
        // sich der Entry-Approve-Endpunkt auf einen bereits veröffentlichten
        // Eintrag mit pending Update anwenden – das würde das Update genehmigen
        // UND approvedCount erneut hochzählen. Updates laufen über approveVersion.
        if ($entry->status !== EntryStatus::Pending) {
            throw new \RuntimeException('Nur wartende Einträge können freigegeben werden');
        }

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
     * »rejected« gesetzt, der Entry auf »deleted« und die Screenshot-Referenz
     * genullt. Wirft RuntimeException, wenn der Entry nicht »pending« ist —
     * sonst ließe sich ein bereits veröffentlichter Eintrag über Reject hart
     * löschen. Gibt den absoluten Pfad der zu löschenden Screenshot-Datei zurück
     * (oder null); der Aufrufer löscht die Datei erst NACH erfolgreichem Commit
     * (sonst bliebe bei einem Rollback die Datei verschwunden, die DB aber intakt).
     */
    public function rejectEntry(Entry $entry): ?string
    {
        if ($entry->status !== EntryStatus::Pending) {
            throw new \RuntimeException('Nur wartende Einträge können abgelehnt werden');
        }

        foreach ($this->versions->findBy(['entry' => $entry, 'status' => VersionStatus::Pending]) as $version) {
            $version->status = VersionStatus::Rejected;
        }
        $screenshotPath = $this->screenshots->absolutePath($entry);
        $entry->status = EntryStatus::Deleted;
        $entry->screenshotPath = null;
        $entry->touch();
        $this->em->flush();

        return $screenshotPath;
    }

    /**
     * Gibt eine einzelne Version frei: setzt ihren Status auf »approved«, trägt sie
     * als currentVersion des Entries ein und aktualisiert abgeleitete Felder
     * (Domains, Tags) via SubmissionService.
     */
    public function approveVersion(EntryVersion $version): void
    {
        // Nur wartende Versionen sind freigebbar. Ohne diesen Guard ließe sich
        // eine bereits abgelehnte Version reaktivieren oder eine schon
        // freigegebene erneut »freigeben« und currentVersion zurückstufen.
        if ($version->status !== VersionStatus::Pending) {
            throw new \RuntimeException('Nur wartende Versionen können freigegeben werden');
        }

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
     * Nur wartende Versionen sind ablehnbar; da currentVersion per Invariante
     * stets »approved« ist, kann so nie die aktuelle Version abgelehnt werden
     * (die sonst weiterverwiesen würde, während ihr Download 404 liefert).
     */
    public function rejectVersion(EntryVersion $version): void
    {
        if ($version->status !== VersionStatus::Pending) {
            throw new \RuntimeException('Nur wartende Versionen können abgelehnt werden');
        }

        $version->status = VersionStatus::Rejected;
        $this->em->flush();
    }

    /**
     * Schließt eine Meldung ab und setzt den Entry-Status entsprechend.
     * Bei publish=true werden zusätzlich alle offenen Meldungen desselben Eintrags
     * aufgelöst, damit ein erneuter einzelner Report nicht sofort wieder den
     * Hide-Threshold erreicht. Bei publish=false wird die Screenshot-Referenz
     * genullt; der absolute Dateipfad wird zurückgegeben, damit der Aufrufer die
     * Datei erst NACH erfolgreichem Commit löscht (sonst Datei weg, DB aber
     * zurückgerollt). Gibt null zurück, wenn keine Datei zu löschen ist.
     */
    public function resolveReport(Report $report, bool $publish): ?string
    {
        // Nur offene Meldungen sind auflösbar — sonst ließe sich über einen
        // alten, längst abgeschlossenen Report der Entry-Status erneut kippen.
        if ($report->status !== ReportStatus::Open) {
            throw new \RuntimeException('Meldung ist bereits abgeschlossen');
        }

        $entry = $report->entry;

        // Ein (soft-)gelöschter Eintrag bleibt gelöscht. Ohne diesen Guard
        // könnte ein alter offener Report einen zwischenzeitlich gelöschten
        // Eintrag wieder sichtbar machen.
        if ($entry->status === EntryStatus::Deleted) {
            throw new \RuntimeException('Eintrag ist gelöscht');
        }

        if ($publish) {
            // Einen Eintrag eines gesperrten Submitters nicht über die
            // Report-Auflösung wieder veröffentlichen (erst entsperren).
            if ($entry->submitter->banned) {
                throw new \RuntimeException('Submitter ist gesperrt — erst entsperren');
            }
            // Published impliziert currentVersion !== null (Statusmaschinen-Invariante).
            if ($entry->currentVersion === null) {
                throw new \RuntimeException('Eintrag hat keine freigegebene Version');
            }
        }

        $report->status = ReportStatus::Resolved;
        $entry->status = $publish ? EntryStatus::Published : EntryStatus::Deleted;
        $screenshotPath = null;
        if (!$publish) {
            $screenshotPath = $this->screenshots->absolutePath($entry);
            $entry->screenshotPath = null;
        }
        $entry->touch();

        if ($publish) {
            $this->resolveOpenReports($entry);
        }

        $this->em->flush();

        return $screenshotPath;
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
