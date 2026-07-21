<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Enum\EntryStatus;
use App\Exception\ApiProblem;
use App\Repository\EntryRepository;
use App\Service\RateLimitGuard;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Endpunkt für den anonymen Install-Zähler.
 *
 * Erhöht den Zähler eines veröffentlichten Eintrags. Die Client-IP wird
 * ausschließlich im Limiter-Cache gehalten und nie persistiert.
 */
final class InstallController
{
    /**
     * Inkrementiert installCount des Eintrags und liefert 204.
     *
     * Erlaubt pro IP und Eintrag maximal einen Ping pro Tag (Rate-Limit).
     * Wirft ApiProblem 404, wenn kein veröffentlichter Eintrag gefunden wird,
     * und 429 bei überschrittenem Rate-Limit.
     */
    #[Route('/api/v1/entries/{formatId}/install', methods: ['POST'])]
    public function __invoke(
        string $formatId,
        Request $request,
        EntryRepository $entries,
        RateLimitGuard $guard,
        RateLimiterFactoryInterface $installLimiter,
    ): Response {
        // 1 Ping pro Tag, IP und Entry — die IP lebt nur im Limiter-Cache.
        $guard->consume($installLimiter, ($request->getClientIp() ?? 'unknown') . '|' . $formatId);

        $entry = $entries->findOneBy(['formatId' => $formatId, 'status' => EntryStatus::Published])
            ?? throw new ApiProblem(404, 'Entry not found');

        // Atomares UPDATE statt Read-modify-write: verhindert Zählverluste bei
        // parallelen Requests (siehe EntryRepository::incrementInstallCount()).
        $entries->incrementInstallCount($entry);

        return new Response('', 204);
    }
}
