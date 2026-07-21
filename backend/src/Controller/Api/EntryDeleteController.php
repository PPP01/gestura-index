<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Enum\EntryStatus;
use App\Exception\ApiProblem;
use App\Repository\EntryRepository;
use App\Service\ScreenshotStorage;
use App\Service\SubmitterResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Endpunkt zum Löschen eines eigenen Eintrags.
 *
 * Setzt den Status des Eintrags auf »Deleted« (Soft-Delete) und entfernt
 * den zugehörigen Screenshot, sofern vorhanden.
 */
final class EntryDeleteController
{
    /**
     * Markiert den Eintrag als gelöscht und bereinigt Mediendateien.
     *
     * Liefert 204 bei Erfolg. Wirft ApiProblem 404, wenn der Eintrag nicht
     * existiert oder bereits gelöscht ist. Wirft ApiProblem 403, wenn der
     * Aufrufer nicht der Eigentümer des Eintrags ist.
     */
    #[Route('/api/v1/entries/{formatId}', methods: ['DELETE'])]
    public function __invoke(
        string $formatId,
        Request $request,
        EntryRepository $entries,
        SubmitterResolver $resolver,
        EntityManagerInterface $em,
        ScreenshotStorage $screenshots,
    ): Response {
        $entry = $entries->findOneBy(['formatId' => $formatId]);
        if ($entry === null || $entry->status === EntryStatus::Deleted) {
            throw new ApiProblem(404, 'Entry not found');
        }
        $resolver->requireOwner($request, $entry);

        $entry->status = EntryStatus::Deleted;
        $screenshots->remove($entry);
        $em->flush();

        return new Response('', 204);
    }
}
