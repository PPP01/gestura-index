<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Enum\EntryStatus;
use App\Exception\ApiProblem;
use App\Repository\EntryRepository;
use App\Service\RateLimitGuard;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

final class InstallController
{
    #[Route('/api/v1/entries/{formatId}/install', methods: ['POST'])]
    public function __invoke(
        string $formatId,
        Request $request,
        EntryRepository $entries,
        EntityManagerInterface $em,
        RateLimitGuard $guard,
        RateLimiterFactoryInterface $installLimiter,
    ): Response {
        // 1 Ping pro Tag, IP und Entry — die IP lebt nur im Limiter-Cache.
        $guard->consume($installLimiter, ($request->getClientIp() ?? 'unknown') . '|' . $formatId);

        $entry = $entries->findOneBy(['formatId' => $formatId, 'status' => EntryStatus::Published])
            ?? throw new ApiProblem(404, 'Entry not found');

        ++$entry->installCount;
        $em->flush();

        return new Response('', 204);
    }
}
