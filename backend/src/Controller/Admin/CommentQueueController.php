<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Repository\RatingRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Liefert die Kommentar-Moderations-Warteschlange: Bewertungen mit wartendem
 * Kommentar (commentStatus = pending), älteste zuerst. Ungeschützt-lesend wie
 * QueueController (die Aktionen selbst tragen die Gates).
 */
final class CommentQueueController
{
    #[Route('/api/admin/comments', methods: ['GET'])]
    public function __invoke(RatingRepository $ratings): JsonResponse
    {
        $out = [];
        foreach ($ratings->pendingComments() as $rating) {
            $out[] = [
                'id' => $rating->id,
                'entryId' => $rating->entry->id,
                'formatId' => $rating->entry->formatId,
                'stars' => $rating->stars,
                'comment' => $rating->comment,
                'createdAt' => $rating->createdAt->format(\DateTimeInterface::ATOM),
            ];
        }

        return new JsonResponse($out);
    }
}
