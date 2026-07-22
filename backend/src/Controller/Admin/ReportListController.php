<?php
declare(strict_types=1);
namespace App\Controller\Admin;

use App\Enum\ReportStatus;
use App\Repository\ReportRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class ReportListController
{
    #[Route('/api/admin/reports', methods: ['GET'])]
    public function __invoke(ReportRepository $reports): JsonResponse
    {
        $open = $reports->findBy(['status' => ReportStatus::Open], ['createdAt' => 'ASC']);

        return new JsonResponse(array_map(static fn ($r): array => [
            'id' => $r->id,
            'entryId' => $r->entry->id,
            'formatId' => $r->entry->formatId,
            'reason' => $r->reason->value,
            'comment' => $r->comment,
            'createdAt' => $r->createdAt->format(\DateTimeInterface::ATOM),
        ], $open));
    }
}
