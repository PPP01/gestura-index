<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Entry;
use App\Entity\EntryVersion;
use App\Entity\Submitter;
use App\Enum\EntryType;
use App\Exception\ApiProblem;
use App\Repository\EntryRepository;
use App\Service\EditTokenService;
use App\Service\PayloadAnalyzer;
use App\Service\RateLimitGuard;
use App\Service\SubmissionService;
use App\Service\SubmitterResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

final class EntrySubmitController
{
    #[Route('/api/v1/entries', methods: ['POST'])]
    public function __invoke(
        Request $request,
        SubmissionService $submission,
        SubmitterResolver $resolver,
        EditTokenService $tokens,
        PayloadAnalyzer $analyzer,
        EntryRepository $entries,
        EntityManagerInterface $em,
        RateLimitGuard $guard,
        RateLimiterFactoryInterface $submitLimiter,
    ): JsonResponse {
        $guard->consume($submitLimiter, $request->getClientIp() ?? 'unknown');

        $submitter = $resolver->resolve($request);
        if ($submitter?->banned === true) {
            throw new ApiProblem(403, 'Submitter is banned');
        }

        $meta = $submission->parseSubmissionBody($request);
        $result = $submission->validatePayload($meta['payloadJson'], null, null);
        $payload = $result->payload;
        $formatId = $payload['id'];

        if ($entries->findOneBy(['formatId' => $formatId]) !== null) {
            throw new ApiProblem(409, 'formatId is already taken');
        }
        $hash = $submission->assertNoDuplicate($payload, null);

        $freshToken = null;
        if ($submitter === null) {
            $generated = $tokens->generate();
            $freshToken = $generated->token;
            $submitter = new Submitter($generated->selector, $generated->hash);
            $em->persist($submitter);
        }

        $entry = new Entry($formatId, EntryType::from($result->type), $submitter);
        $submission->applyMetadata($entry, $meta);
        $submission->refreshDerived($entry, $payload);

        $version = new EntryVersion($entry, $payload['version'], $payload, $hash);
        $version->changelog = $meta['changelog'];
        $version->hasTransformCode = $analyzer->hasTransform($payload);

        $em->persist($entry);
        $em->persist($version);
        $em->flush();

        $response = ['formatId' => $entry->formatId, 'status' => $entry->status->value];
        if ($freshToken !== null) {
            $response['editToken'] = $freshToken; // einzige Stelle, an der das Token je herausgegeben wird
        }

        return new JsonResponse($response, 201);
    }
}
