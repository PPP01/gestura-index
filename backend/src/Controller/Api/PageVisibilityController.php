<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\PageVisibility\ToggleablePages;
use App\Repository\PageSettingRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Öffentliche, cookielose Sichtbarkeits-Map der schaltbaren Marketing-Seiten.
 * Liefert IMMER alle Whitelist-Keys (fehlende Zeile ⇒ aktiv). Kurz gecacht,
 * damit Admin-Toggles zeitnah greifen.
 */
final class PageVisibilityController
{
    #[Route('/api/v1/pages', methods: ['GET'])]
    public function __invoke(PageSettingRepository $repo): JsonResponse
    {
        $stored = $repo->findAllIndexed();
        $out = [];
        foreach (ToggleablePages::KEYS as $key) {
            $out[$key] = $stored[$key] ?? true;
        }

        $response = new JsonResponse($out);
        $response->setEtag(sha1((string) $response->getContent()));
        $response->setPublic();
        $response->setMaxAge(60);

        return $response;
    }
}
