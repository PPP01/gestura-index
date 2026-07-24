<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Rating;
use App\Enum\EntryStatus;
use App\Exception\ApiProblem;
use App\Repository\EntryRepository;
use App\Service\AccountResolver;
use App\Service\RateLimitGuard;
use App\Service\RatingService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Legt die Bewertung des Kontos für einen veröffentlichten Eintrag an oder
 * aktualisiert sie (Upsert, eine pro Konto & Eintrag). Stern zählt sofort;
 * Kommentar durchläuft die Hybrid-Moderation.
 */
final class RatingPutController
{
    #[Route('/api/v1/entries/{formatId}/rating', methods: ['PUT'])]
    public function __invoke(
        string $formatId,
        Request $request,
        AccountResolver $resolver,
        EntryRepository $entries,
        RatingService $ratingService,
        RateLimitGuard $guard,
        RateLimiterFactoryInterface $ratingWriteLimiter,
    ): JsonResponse {
        $account = $resolver->requireAccount($request);
        $guard->consume($ratingWriteLimiter, $request->getClientIp() ?? 'unknown');

        $entry = $entries->findOneBy(['formatId' => $formatId, 'status' => EntryStatus::Published])
            ?? throw new ApiProblem(404, 'Entry not found');

        $data = json_decode((string) $request->getContent(), true);
        if (!\is_array($data)) {
            throw new ApiProblem(400, 'Invalid JSON');
        }

        $stars = $data['stars'] ?? null;
        if (!\is_int($stars) || $stars < 1 || $stars > 5) {
            throw new ApiProblem(400, 'stars must be an integer between 1 and 5');
        }

        $comment = $data['comment'] ?? null;
        if ($comment !== null) {
            if (!\is_string($comment)) {
                throw new ApiProblem(400, 'comment must be a string');
            }
            if (mb_strlen($comment) > Rating::MAX_COMMENT_LENGTH) {
                throw new ApiProblem(400, 'comment too long');
            }
        }

        $rating = $ratingService->upsert($account, $entry, $stars, $comment);

        return new JsonResponse([
            'stars' => $rating->stars,
            'comment' => $rating->comment,
            'commentStatus' => $rating->commentStatus->value,
        ]);
    }
}
