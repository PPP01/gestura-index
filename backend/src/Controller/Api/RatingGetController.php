<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Exception\ApiProblem;
use App\Repository\EntryRepository;
use App\Repository\RatingRepository;
use App\Service\AccountResolver;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Liefert die eigene Bewertung des Kontos zu einem Eintrag inkl.
 * Kommentar-Moderationsstatus, oder 404, wenn keine vorhanden ist.
 */
final class RatingGetController
{
    #[Route('/api/v1/entries/{formatId}/rating', methods: ['GET'])]
    public function __invoke(
        string $formatId,
        Request $request,
        AccountResolver $resolver,
        EntryRepository $entries,
        RatingRepository $ratings,
    ): JsonResponse {
        $account = $resolver->requireAccount($request);
        $entry = $entries->findOneBy(['formatId' => $formatId]) ?? throw new ApiProblem(404, 'Entry not found');
        $rating = $ratings->findForAccountAndEntry($account, $entry) ?? throw new ApiProblem(404, 'No rating');

        return new JsonResponse([
            'stars' => $rating->stars,
            'comment' => $rating->comment,
            'commentStatus' => $rating->commentStatus->value,
            'createdAt' => $rating->createdAt->format(\DateTimeInterface::ATOM),
        ]);
    }
}
