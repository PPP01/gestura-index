<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Exception\ApiProblem;
use App\Service\ScreenshotProcessor;
use PHPUnit\Framework\TestCase;

/**
 * Prüft die Fehlerpfade von ScreenshotProcessor isoliert (ohne HTTP-Upload):
 * nicht lesbare Datei und ein Bild, dessen Header zwar lesbar ist, dessen
 * Bilddaten aber nicht vollständig dekodierbar sind.
 */
final class ScreenshotProcessorTest extends TestCase
{
    public function testUnreadableFileIsRejected(): void
    {
        $this->expectException(ApiProblem::class);
        $this->expectExceptionMessage('File is not a decodable image');

        (new ScreenshotProcessor())->process('/nicht/existierender/pfad.png');
    }

    /**
     * getimagesizefromstring() liest nur PNG-Signatur + IHDR-Chunk (die
     * ersten Bytes) und ermittelt daraus bereits Breite/Höhe – auch wenn die
     * eigentlichen Bilddaten fehlen. imagecreatefromstring() braucht dagegen
     * die vollständigen Bilddaten und scheitert an so einer abgeschnittenen
     * Datei. Beide Fälle müssen als "nicht dekodierbares Bild" abgelehnt werden.
     */
    public function testImageWithReadableHeaderButUndecodableDataIsRejected(): void
    {
        $img = imagecreatetruecolor(50, 50);
        imagefill($img, 0, 0, (int) imagecolorallocate($img, 10, 20, 30));
        ob_start();
        imagepng($img);
        $full = (string) ob_get_clean();

        $path = tempnam(sys_get_temp_dir(), 'trunc') . '.png';
        // Nur PNG-Signatur + IHDR-Chunk (33 Bytes): Header liefert Breite/Höhe,
        // die eigentlichen Bilddaten (IDAT/IEND) fehlen komplett.
        file_put_contents($path, substr($full, 0, 33));

        $this->expectException(ApiProblem::class);
        $this->expectExceptionMessage('File is not a decodable image');
        try {
            (new ScreenshotProcessor())->process($path);
        } finally {
            unlink($path);
        }
    }
}
