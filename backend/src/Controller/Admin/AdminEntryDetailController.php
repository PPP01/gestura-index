<?php
declare(strict_types=1);
namespace App\Controller\Admin;

use App\Enum\ReportStatus;
use App\Exception\ApiProblem;
use App\Repository\EntryRepository;
use App\Repository\EntryVersionRepository;
use App\Repository\ReportRepository;
use App\Service\EntrySerializer;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Admin-Detailansicht eines Eintrags: alle Versionen (nicht nur approbierte,
 * damit die Moderation auch pending/rejected Versionen sieht) sowie die
 * offenen Meldungen des Eintrags.
 */
final class AdminEntryDetailController
{
    #[Route('/api/admin/entries/{id}', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function __invoke(
        int $id,
        EntryRepository $entries,
        EntryVersionRepository $versions,
        ReportRepository $reports,
        EntrySerializer $serializer,
    ): JsonResponse {
        $entry = $entries->find($id) ?? throw new ApiProblem(404, 'Entry not found');

        $versionList = $versions->findBy(['entry' => $entry], ['submittedAt' => 'DESC']);
        $detail = $serializer->toDetail($entry, $versionList);

        $detail['status'] = $entry->status->value;
        $detail['submitterId'] = $entry->submitter->id;
        $detail['submitterBanned'] = $entry->submitter->banned;

        $detail['openReports'] = array_map(static fn ($r): array => [
            'id' => $r->id,
            'reason' => $r->reason->value,
            'comment' => $r->comment,
            'createdAt' => $r->createdAt->format(\DateTimeInterface::ATOM),
        ], $reports->findBy(['entry' => $entry, 'status' => ReportStatus::Open], ['createdAt' => 'DESC']));

        return new JsonResponse($detail);
    }
}
