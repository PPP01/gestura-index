<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Enum\EntryStatus;
use App\Exception\ApiProblem;
use App\Repository\EntryRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class UpdateCheckController
{
    #[Route('/api/v1/entries/updates', methods: ['POST'])]
    public function __invoke(Request $request, EntryRepository $entries): JsonResponse
    {
        try {
            $body = json_decode($request->getContent(), true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ApiProblem(400, 'Invalid JSON body');
        }

        $list = $body['entries'] ?? null;
        if (!\is_array($list) || \count($list) > 200) {
            throw new ApiProblem(400, 'entries must be a list with at most 200 items');
        }

        $updates = [];
        foreach ($list as $item) {
            $id = $item['id'] ?? null;
            $version = $item['version'] ?? null;
            if (!\is_string($id) || !\is_string($version) || !preg_match('/^\d{1,5}\.\d{1,5}\.\d{1,5}$/', $version)) {
                continue; // fehlerhafte Einzelposten still überspringen — der Check bleibt nutzbar
            }
            $entry = $entries->findOneBy(['formatId' => $id, 'status' => EntryStatus::Published]);
            $latest = $entry?->currentVersion?->semver;
            if ($latest !== null && version_compare($latest, $version, '>')) {
                $updates[] = [
                    'id' => $entry->formatId,
                    'latestVersion' => $latest,
                    'deprecated' => $entry->deprecated,
                    'successorFormatId' => $entry->successorFormatId,
                ];
            }
        }

        return new JsonResponse(['updates' => $updates]);
    }
}
