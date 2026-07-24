<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Exception\ApiProblem;
use App\Repository\EntryRepository;
use App\Service\AccountResolver;
use App\Service\RateLimitGuard;
use App\Service\RatingService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Entfernt die eigene Bewertung des Kontos zu einem Eintrag (204); 404, wenn
 * keine vorhanden ist. Das Aggregat des Eintrags passt sich lesend automatisch an.
 */
final class RatingDeleteController
{
    #[Route('/api/v1/entries/{formatId}/rating', methods: ['DELETE'])]
    public function __invoke(
        string $formatId,
        Request $request,
        AccountResolver $resolver,
        EntryRepository $entries,
        RatingService $ratingService,
        RateLimitGuard $guard,
        RateLimiterFactoryInterface $ratingWriteLimiter,
    ): Response {
        $account = $resolver->requireAccount($request);
        $guard->consume($ratingWriteLimiter, $request->getClientIp() ?? 'unknown');

        $entry = $entries->findOneBy(['formatId' => $formatId]) ?? throw new ApiProblem(404, 'Entry not found');
        if (!$ratingService->delete($account, $entry)) {
            throw new ApiProblem(404, 'No rating');
        }

        return new Response('', 204);
    }
}
