<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Rating;
use App\Enum\EntryStatus;
use App\Exception\ApiProblem;
use App\Repository\EntryRepository;
use App\Repository\RatingRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Öffentliche, paginierte Liste der freigegebenen Kommentare eines Eintrags –
 * anonym (kein Autor-Bezug). Bewertungen ohne Kommentar oder mit noch nicht
 * freigegebenem/abgelehntem Kommentar erscheinen nicht.
 */
final class ReviewListController
{
    private const MAX_PAGE = 100_000;

    #[Route('/api/v1/entries/{formatId}/reviews', methods: ['GET'])]
    public function __invoke(
        string $formatId,
        Request $request,
        EntryRepository $entries,
        RatingRepository $ratings,
    ): JsonResponse {
        $entry = $entries->findOneBy(['formatId' => $formatId, 'status' => EntryStatus::Published])
            ?? throw new ApiProblem(404, 'Entry not found');

        $page = min(self::MAX_PAGE, max(1, $request->query->getInt('page', 1)));
        $perPage = min(50, max(1, $request->query->getInt('perPage', 20)));

        $items = array_map(static fn (Rating $r): array => [
            'stars' => $r->stars,
            'comment' => $r->comment,
            'createdAt' => $r->createdAt->format(\DateTimeInterface::ATOM),
        ], $ratings->approvedComments($entry, ($page - 1) * $perPage, $perPage));

        $response = new JsonResponse([
            'items' => $items,
            'page' => $page,
            'perPage' => $perPage,
            'total' => $ratings->countApprovedComments($entry),
        ]);
        $response->setEtag(sha1((string) $response->getContent()));
        $response->setPublic();
        $response->setMaxAge(300);
        $response->isNotModified($request);

        return $response;
    }
}
