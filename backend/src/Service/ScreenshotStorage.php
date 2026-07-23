<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Entry;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Kapselt die Ablage der Screenshot-Dateien. Bilder liegen bewusst PRIVAT
 * außerhalb des öffentlichen Docroots (var/media/screenshots/) und werden nur
 * über einen statusgeprüften Controller ausgeliefert. Andernfalls wären Bilder
 * zu pending/hidden Einträgen unter einer erratbaren URL öffentlich abrufbar
 * (Moderations-Umgehung).
 */
final class ScreenshotStorage
{
    private readonly string $baseDir;

    private readonly LoggerInterface $logger;

    /**
     * Leitet aus dem Projekt-Root (Symfony-Parameter kernel.project_dir) das
     * private Screenshot-Verzeichnis ab.
     */
    public function __construct(
        #[Autowire('%kernel.project_dir%')] string $projectDir,
        ?LoggerInterface $logger = null,
    ) {
        $this->baseDir = $projectDir . '/var/media/screenshots';
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Schreibt die WebP-Binärdaten in den privaten Speicher und hinterlegt den
     * Dateinamen in $entry->screenshotPath. Legt das Verzeichnis bei Bedarf an.
     */
    public function store(Entry $entry, string $webp): void
    {
        if (!is_dir($this->baseDir)) {
            mkdir($this->baseDir, 0775, true);
        }
        $filename = $entry->formatId . '.webp';
        file_put_contents($this->baseDir . '/' . $filename, $webp);
        $entry->screenshotPath = $filename;
    }

    /**
     * Gibt den absoluten Pfad zur Screenshot-Datei des Eintrags zurück,
     * oder null, wenn kein Screenshot hinterlegt ist.
     */
    public function absolutePath(Entry $entry): ?string
    {
        if ($entry->screenshotPath === null) {
            return null;
        }

        return $this->baseDir . '/' . $entry->screenshotPath;
    }

    /**
     * Löscht eine zuvor per absolutePath() erfasste Datei. Gedacht für die
     * Ausführung NACH einem erfolgreichen DB-Commit: so bleibt bei einem
     * Rollback die Datei erhalten (DB und Dateisystem bleiben konsistent).
     * Ein fehlgeschlagenes unlink() wird geloggt statt verschluckt.
     */
    public function deleteFileAt(?string $absolutePath): void
    {
        if ($absolutePath === null || !is_file($absolutePath)) {
            return;
        }
        if (!@unlink($absolutePath)) {
            $this->logger->warning('Screenshot-Datei konnte nicht gelöscht werden', ['path' => $absolutePath]);
        }
    }
}
