<?php
declare(strict_types=1);
namespace App\Controller\Admin;

use App\Repository\AuditLogEntryRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class AuditListController
{
    #[Route('/api/admin/audit', methods: ['GET'])]
    public function __invoke(Request $request, AuditLogEntryRepository $repo): JsonResponse
    {
        $page = max(1, (int) $request->query->get('page', '1'));
        $perPage = min(100, max(1, (int) $request->query->get('perPage', '50')));
        $items = [];
        foreach ($repo->page($page, $perPage) as $a) {
            $items[] = [
                'id' => $a->id,
                'actor' => $a->actor?->email,
                'action' => $a->action,
                'targetType' => $a->targetType,
                'targetId' => $a->targetId,
                'detail' => $a->detail,
                'createdAt' => $a->createdAt->format(\DateTimeInterface::ATOM),
            ];
        }
        return new JsonResponse(['items' => $items, 'page' => $page, 'perPage' => $perPage]);
    }
}
