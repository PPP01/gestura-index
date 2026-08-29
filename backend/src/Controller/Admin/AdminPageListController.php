<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\PageVisibility\ToggleablePages;
use App\Repository\PageSettingRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Liste aller schaltbaren Seiten mit ihrem aktuellen Sichtbarkeits-Status (für
 * die Admin-UI). Liefert alle Whitelist-Keys, auch ohne DB-Zeile (dann aktiv).
 */
final class AdminPageListController
{
    #[Route('/api/admin/pages', methods: ['GET'])]
    public function __invoke(PageSettingRepository $repo): JsonResponse
    {
        $byKey = [];
        foreach ($repo->findAll() as $ps) {
            $byKey[$ps->pageKey] = $ps;
        }

        $out = [];
        foreach (ToggleablePages::KEYS as $key) {
            $ps = $byKey[$key] ?? null;
            $out[] = [
                'pageKey' => $key,
                'enabled' => $ps?->enabled ?? true,
                'updatedAt' => $ps?->updatedAt->format(\DATE_ATOM),
                'updatedBy' => $ps?->updatedBy,
            ];
        }

        return new JsonResponse($out);
    }
}
