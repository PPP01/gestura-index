<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Report;
use App\Enum\EntryStatus;
use App\Enum\ReportReason;
use App\Exception\ApiProblem;
use App\Repository\EntryRepository;
use App\Repository\ReportRepository;
use App\Service\RateLimitGuard;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

final class ReportController
{
    #[Route('/api/v1/entries/{formatId}/report', methods: ['POST'])]
    public function __invoke(
        string $formatId,
        Request $request,
        EntryRepository $entries,
        ReportRepository $reports,
        EntityManagerInterface $em,
        RateLimitGuard $guard,
        RateLimiterFactoryInterface $reportLimiter,
        #[Autowire('%app.report_hide_threshold%')] int $hideThreshold,
    ): Response {
        $guard->consume($reportLimiter, $request->getClientIp() ?? 'unknown');

        $entry = $entries->findOneBy(['formatId' => $formatId, 'status' => EntryStatus::Published])
            ?? throw new ApiProblem(404, 'Entry not found');

        try {
            $body = json_decode($request->getContent(), true, 4, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ApiProblem(400, 'Invalid JSON body');
        }

        $reason = \is_string($body['reason'] ?? null)
            ? (ReportReason::tryFrom($body['reason']) ?? throw new ApiProblem(400, 'Unknown reason'))
            : throw new ApiProblem(400, 'Unknown reason');

        $comment = $body['comment'] ?? null;
        if ($comment !== null && (!\is_string($comment) || mb_strlen($comment) > 2000)) {
            throw new ApiProblem(400, 'Invalid comment');
        }

        $em->persist(new Report($entry, $reason, $comment));
        $em->flush();

        if ($reports->countOpenFor($entry) >= $hideThreshold) {
            $entry->status = EntryStatus::Hidden; // automatisch bis zur Admin-Prüfung
            $em->flush();
        }

        return new Response('', 204);
    }
}
