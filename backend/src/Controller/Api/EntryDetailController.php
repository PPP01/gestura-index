<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Enum\EntryStatus;
use App\Exception\ApiProblem;
use App\Repository\EntryRepository;
use App\Repository\EntryVersionRepository;
use App\Service\EntrySerializer;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class EntryDetailController
{
    #[Route('/api/v1/entries/{formatId}', methods: ['GET'])]
    public function __invoke(
        string $formatId,
        Request $request,
        EntryRepository $entries,
        EntryVersionRepository $versions,
        EntrySerializer $serializer,
    ): JsonResponse {
        $entry = $entries->findOneBy(['formatId' => $formatId, 'status' => EntryStatus::Published])
            ?? throw new ApiProblem(404, 'Entry not found');

        $response = new JsonResponse($serializer->toDetail($entry, $versions->findApproved($entry)));
        $response->setEtag(sha1((string) $response->getContent()));
        $response->setPublic();
        $response->setMaxAge(300);
        $response->isNotModified($request);

        return $response;
    }
}
