<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Entry;
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

    /**
     * Leitet aus dem Projekt-Root (Symfony-Parameter kernel.project_dir) das
     * private Screenshot-Verzeichnis ab.
     */
    public function __construct(#[Autowire('%kernel.project_dir%')] string $projectDir)
    {
        $this->baseDir = $projectDir . '/var/media/screenshots';
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
     * Löscht die Screenshot-Datei des Eintrags vom Dateisystem und setzt
     * $entry->screenshotPath auf null. Ist kein Screenshot gesetzt oder die
     * Datei bereits verschwunden, wird kein Fehler ausgelöst.
     */
    public function remove(Entry $entry): void
    {
        $file = $this->absolutePath($entry);
        if ($file === null) {
            return;
        }
        if (is_file($file)) {
            @unlink($file);
        }
        $entry->screenshotPath = null;
    }
}
