<?php
declare(strict_types=1);
namespace App\Controller\Admin;

use App\Entity\AdminUser;
use App\Exception\ApiProblem;
use App\Repository\ReportRepository;
use App\Service\AuditLogger;
use App\Service\ModerationService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ReportResolveController
{
    #[Route('/api/admin/reports/{id}/resolve', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function __invoke(int $id, Request $request, ReportRepository $reports, ModerationService $moderation, AuditLogger $audit, Security $security): Response
    {
        $report = $reports->find($id) ?? throw new ApiProblem(404, 'Report not found');

        try {
            $body = json_decode($request->getContent(), true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ApiProblem(400, 'Invalid JSON body');
        }
        if (!\is_bool($body['publish'] ?? null)) {
            throw new ApiProblem(400, '»publish« muss ein Boolean sein');
        }
        $publish = $body['publish'];

        try {
            $moderation->resolveReport($report, $publish);
        } catch (\RuntimeException $e) {
            throw new ApiProblem(409, $e->getMessage());
        }

        /** @var AdminUser $actor */
        $actor = $security->getUser();
        $audit->log($actor, 'report.resolve', 'report', (string) $report->id, ['publish' => $publish]);

        return new Response('', 204);
    }
}
