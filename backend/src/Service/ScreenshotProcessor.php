<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\ApiProblem;

/**
 * Re-enkodiert Uploads IMMER neu (GD): schneidet eingebettete Metadaten
 * und potentiell präparierte Container ab — es wird nie das Original
 * gespeichert (Spec, Abschnitt Screenshots).
 */
final class ScreenshotProcessor
{
    private const MAX_WIDTH = 1280;
    private const MAX_HEIGHT = 800;
    private const WEBP_QUALITY = 82;
    private const MAX_SOURCE_WIDTH = 4000;
    private const MAX_SOURCE_HEIGHT = 4000;

    /** @return string WebP-Binärdaten */
    public function process(string $sourcePath): string
    {
        $raw = @file_get_contents($sourcePath);
        if ($raw === false) {
            throw new ApiProblem(400, 'File is not a decodable image');
        }

        // Nur den Header lesen (kein Bitmap-Alloc) — verhindert, dass eine
        // hochkomprimierte Riesen-Datei beim Dekodieren Breite×Höhe×4 Bytes
        // alloziert (Decompression-Bomb / Memory-DoS).
        $info = @getimagesizefromstring($raw);
        if ($info === false || $info[0] > self::MAX_SOURCE_WIDTH || $info[1] > self::MAX_SOURCE_HEIGHT) {
            throw new ApiProblem(400, 'Image dimensions not supported');
        }

        $image = @imagecreatefromstring($raw);
        if ($image === false) {
            throw new ApiProblem(400, 'File is not a decodable image');
        }

        $width = imagesx($image);
        $height = imagesy($image);
        $scale = min(self::MAX_WIDTH / $width, self::MAX_HEIGHT / $height, 1.0);
        if ($scale < 1.0) {
            $scaled = imagescale($image, (int) round($width * $scale), (int) round($height * $scale), IMG_BICUBIC);
            if ($scaled === false) {
                throw new ApiProblem(400, 'Image could not be processed');
            }
            $image = $scaled;
        }

        ob_start();
        $ok = imagewebp($image, null, self::WEBP_QUALITY);
        $webp = (string) ob_get_clean();
        if (!$ok || $webp === '') {
            throw new ApiProblem(400, 'Image could not be encoded');
        }

        return $webp;
    }
}
