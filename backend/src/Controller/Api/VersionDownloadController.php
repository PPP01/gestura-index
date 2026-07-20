<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Enum\EntryStatus;
use App\Exception\ApiProblem;
use App\Repository\EntryRepository;
use App\Repository\EntryVersionRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class VersionDownloadController
{
    #[Route('/api/v1/entries/{formatId}/versions/{semver}', methods: ['GET'], requirements: ['semver' => '\d{1,5}\.\d{1,5}\.\d{1,5}'])]
    public function __invoke(
        string $formatId,
        string $semver,
        Request $request,
        EntryRepository $entries,
        EntryVersionRepository $versions,
    ): JsonResponse {
        $entry = $entries->findOneBy(['formatId' => $formatId, 'status' => EntryStatus::Published])
            ?? throw new ApiProblem(404, 'Entry not found');
        $version = $versions->findOneApproved($entry, $semver)
            ?? throw new ApiProblem(404, 'Version not found');

        $response = new JsonResponse($version->payload);
        $response->setEtag(sha1((string) $response->getContent()));
        $response->setPublic();
        $response->setMaxAge(300);
        $response->isNotModified($request);

        return $response;
    }
}
