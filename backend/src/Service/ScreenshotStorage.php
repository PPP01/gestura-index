<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Entry;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Entfernt die Screenshot-Datei eines Eintrags vom Dateisystem.
 *
 * Ohne diesen Aufruf bliebe der Screenshot nach Delete/Reject/Ban/
 * Resolve(delete) unter seiner erratbaren URL öffentlich erreichbar.
 */
final class ScreenshotStorage
{
    public function __construct(#[Autowire('%kernel.project_dir%')] private readonly string $projectDir)
    {
    }

    public function remove(Entry $entry): void
    {
        if ($entry->screenshotPath === null) {
            return;
        }
        $file = $this->projectDir . '/public/' . $entry->screenshotPath;
        if (is_file($file)) {
            @unlink($file);
        }
        $entry->screenshotPath = null;
    }
}
