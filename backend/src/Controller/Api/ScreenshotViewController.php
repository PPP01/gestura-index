<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Enum\EntryStatus;
use App\Exception\ApiProblem;
use App\Repository\EntryRepository;
use App\Service\ScreenshotStorage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Liefert den Screenshot eines Eintrags aus dem privaten Speicher aus –
 * ausschließlich für veröffentlichte Einträge. Für pending/hidden/deleted
 * Einträge gibt es 404, damit unmoderierte oder ausgeblendete Bilder nicht
 * öffentlich abrufbar sind (der Upload legt sie bewusst außerhalb des Docroots ab).
 */
final class ScreenshotViewController
{
    /**
     * Streamt das WebP-Bild mit 200, wenn der Eintrag veröffentlicht ist und ein
     * Screenshot existiert. Wirft ApiProblem 404 in allen anderen Fällen
     * (unbekannter/nicht veröffentlichter Eintrag, kein oder fehlendes Bild).
     */
    #[Route('/api/v1/entries/{formatId}/screenshot', methods: ['GET'])]
    public function __invoke(string $formatId, EntryRepository $entries, ScreenshotStorage $storage): Response
    {
        $entry = $entries->findOneBy(['formatId' => $formatId, 'status' => EntryStatus::Published]);
        $file = $entry === null ? null : $storage->absolutePath($entry);
        if ($file === null || !is_file($file)) {
            throw new ApiProblem(404, 'Screenshot not found');
        }

        $response = new BinaryFileResponse($file);
        $response->headers->set('Content-Type', 'image/webp');
        $response->setPublic();
        $response->setMaxAge(300);

        return $response;
    }
}
