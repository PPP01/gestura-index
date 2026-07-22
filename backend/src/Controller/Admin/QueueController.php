<?php
declare(strict_types=1);
namespace App\Controller\Admin;

use App\Enum\EntryStatus;
use App\Enum\VersionStatus;
use App\Repository\EntryRepository;
use App\Repository\EntryVersionRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Liefert die Moderations-Warteschlange: neue pending Einträge und wartende
 * Versionen bereits veröffentlichter Einträge (Update-Pfad). Nutzt dieselbe
 * Abgrenzung wie `index:queue` (ModerationQueueCommand): eine Pending-Version
 * eines noch pending Entry erscheint bereits über die Entry-Liste und wird
 * hier bewusst nicht doppelt aufgeführt.
 */
final class QueueController
{
    #[Route('/api/admin/queue', methods: ['GET'])]
    public function __invoke(EntryRepository $entries, EntryVersionRepository $versions): JsonResponse
    {
        $out = ['entries' => [], 'versions' => []];

        foreach ($entries->findBy(['status' => EntryStatus::Pending], ['createdAt' => 'ASC']) as $entry) {
            $out['entries'][] = [
                'id' => $entry->id,
                'formatId' => $entry->formatId,
                'type' => $entry->type->value,
                'createdAt' => $entry->createdAt->format(\DateTimeInterface::ATOM),
            ];
        }

        foreach ($versions->findBy(['status' => VersionStatus::Pending], ['submittedAt' => 'ASC']) as $version) {
            if ($version->entry->status !== EntryStatus::Published) {
                continue;
            }
            $out['versions'][] = [
                'id' => $version->id,
                'entryId' => $version->entry->id,
                'formatId' => $version->entry->formatId,
                'semver' => $version->semver,
                'hasTransformCode' => $version->hasTransformCode,
                'submittedAt' => $version->submittedAt->format(\DateTimeInterface::ATOM),
            ];
        }

        return new JsonResponse($out);
    }
}
