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

final class EntryListController
{
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

        $page = max(1, $request->query->getInt('page', 1));
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
