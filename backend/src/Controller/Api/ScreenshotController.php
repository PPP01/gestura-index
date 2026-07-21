<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Enum\EntryStatus;
use App\Exception\ApiProblem;
use App\Repository\EntryRepository;
use App\Service\ScreenshotProcessor;
use App\Service\ScreenshotStorage;
use App\Service\SubmitterResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Endpunkt zum Hochladen eines Screenshots für einen Eintrag.
 *
 * Nimmt ein Bild als Multipart-Upload entgegen, enkodiert es serverseitig
 * als WebP (Sicherheitsregel: keine Fremd-URLs) und speichert es PRIVAT
 * (var/media/screenshots/) über ScreenshotStorage. Ausgeliefert wird es nur
 * über den statusgeprüften GET-Endpunkt {@see ScreenshotViewController}.
 */
final class ScreenshotController
{
    private const UPLOAD_MAX = 2 * 1024 * 1024; // 2 MB

    /**
     * Verarbeitet den Screenshot-Upload und liefert 200 mit der screenshotUrl.
     *
     * Wirft ApiProblem 400 bei fehlendem oder ungültigem Multipart-Feld
     * »screenshot«, 404 wenn der Eintrag nicht existiert oder gelöscht ist,
     * 403 bei fehlendem Eigentumsnachweis und 413 bei einem Upload über 2 MB.
     */
    #[Route('/api/v1/entries/{formatId}/screenshot', methods: ['POST'])]
    public function __invoke(
        string $formatId,
        Request $request,
        EntryRepository $entries,
        SubmitterResolver $resolver,
        ScreenshotProcessor $processor,
        ScreenshotStorage $storage,
        EntityManagerInterface $em,
    ): JsonResponse {
        $entry = $entries->findOneBy(['formatId' => $formatId]);
        if ($entry === null || $entry->status === EntryStatus::Deleted) {
            throw new ApiProblem(404, 'Entry not found');
        }
        $resolver->requireOwner($request, $entry);

        $upload = $request->files->get('screenshot');
        if (!$upload instanceof UploadedFile || !$upload->isValid()) {
            throw new ApiProblem(400, 'Multipart field "screenshot" required');
        }
        if ($upload->getSize() > self::UPLOAD_MAX) {
            throw new ApiProblem(413, 'Screenshot larger than 2 MB');
        }

        $storage->store($entry, $processor->process($upload->getPathname()));
        $entry->touch();
        $em->flush();

        return new JsonResponse(['screenshotUrl' => '/api/v1/entries/' . $entry->formatId . '/screenshot']);
    }
}
