<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Enum\Category;
use App\Enum\EntryType;
use App\Exception\ApiProblem;
use App\Repository\EntryRepository;
use App\Service\EntrySerializer;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Endpunkt für die paginierte und filterbare Eintragsliste.
 *
 * Unterstützt Freitextsuche sowie Filter nach Kategorie, Typ, Tag und
 * Domain (site). Antwort ist öffentlich cachebar (ETag, max-age 300 s).
 */
final class EntryListController
{
    /** Praktische Obergrenze für die Seitennummer (100.000 × 50 = 5 Mio. Treffer). */
    private const MAX_PAGE = 100_000;

    /**
     * Liefert 200 mit einer paginierten Liste veröffentlichter Einträge.
     *
     * Akzeptiert die Query-Parameter q, site, category, tag, type, sort, page
     * und perPage (max. 50 Einträge pro Seite). Wirft ApiProblem 400 bei
     * ungültigem category-, type- oder sort-Wert. Gibt 304 zurück, wenn ETag
     * unverändert.
     */
    #[Route('/api/v1/entries', methods: ['GET'])]
    public function __invoke(Request $request, EntryRepository $entries, EntrySerializer $serializer): JsonResponse
    {
        $categoryParam = $request->query->get('category');
        $category = $categoryParam === null ? null
            : (Category::tryFrom($categoryParam) ?? throw new ApiProblem(400, 'Unknown category'));

        $typeParam = $request->query->get('type');
        $type = $typeParam === null ? null
            : (EntryType::tryFrom($typeParam) ?? throw new ApiProblem(400, 'Unknown type'));

        $sort = $request->query->get('sort', 'newest');
        if (!\in_array($sort, ['newest', 'installs'], true)) {
            throw new ApiProblem(400, 'Unknown sort');
        }

        // Obergrenze verhindert, dass (page-1)*perPage bei absurd großen
        // Werten (bis PHP_INT_MAX) zu einem float überläuft und setFirstResult()
        // unter strict_types einen TypeError → HTTP 500 wirft.
        $page = min(self::MAX_PAGE, max(1, $request->query->getInt('page', 1)));
        $perPage = min(50, max(1, $request->query->getInt('perPage', 20)));

        $result = $entries->search(
            q: $request->query->get('q'),
            site: $request->query->get('site'),
            category: $category,
            tag: $request->query->get('tag'),
            type: $type,
            sort: $sort,
            page: $page,
            perPage: $perPage,
        );

        $response = new JsonResponse([
            'items' => array_map($serializer->toListItem(...), $result['items']),
            'page' => $page,
            'perPage' => $perPage,
            'total' => $result['total'],
        ]);
        $response->setEtag(sha1((string) $response->getContent()));
        $response->setPublic();
        $response->setMaxAge(300);
        $response->isNotModified($request);

        return $response;
    }
}
