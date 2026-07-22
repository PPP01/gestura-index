<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\Entry;
use App\Entity\Submitter;
use App\Enum\EntryType;
use App\Service\ScreenshotStorage;
use PHPUnit\Framework\TestCase;

/**
 * Prüft ScreenshotStorage isoliert (ohne Symfony-Container): insbesondere,
 * dass store() das private Screenshot-Verzeichnis bei Bedarf selbst anlegt.
 */
final class ScreenshotStorageTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir() . '/gestura-screenshot-test-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        $dir = $this->projectDir . '/var/media/screenshots';
        if (is_dir($dir)) {
            foreach (glob($dir . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($dir);
            @rmdir($this->projectDir . '/var/media');
            @rmdir($this->projectDir . '/var');
            @rmdir($this->projectDir);
        }
    }

    public function testStoreCreatesScreenshotDirectoryWhenMissing(): void
    {
        // Verzeichnis bewusst NICHT vorab anlegen – store() muss das tun.
        self::assertDirectoryDoesNotExist($this->projectDir . '/var/media/screenshots');

        $storage = new ScreenshotStorage($this->projectDir);
        $entry = new Entry('com.example.shop', EntryType::Menu, new Submitter('selector', 'hash'));

        $storage->store($entry, 'webp-bytes');

        self::assertDirectoryExists($this->projectDir . '/var/media/screenshots');
        self::assertFileExists($this->projectDir . '/var/media/screenshots/com.example.shop.webp');
        self::assertSame('com.example.shop.webp', $entry->screenshotPath);
    }
}
