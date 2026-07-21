<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\EntryVersion;
use App\Enum\EntryStatus;
use App\Enum\VersionStatus;
use App\Exception\ApiProblem;
use App\Repository\EntryRepository;
use App\Repository\EntryVersionRepository;
use App\Service\PayloadAnalyzer;
use App\Service\RateLimitGuard;
use App\Service\SubmissionService;
use App\Service\SubmitterResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Endpunkt zum Aktualisieren eines bestehenden Eintrags.
 *
 * Fügt dem Eintrag eine neue EntryVersion hinzu. Bei pending-Einträgen
 * wird die wartende Version ersetzt; Versionen mit transformCode gehen
 * stets in die Moderations-Warteschlange (Supply-Chain-Schutz).
 */
final class EntryUpdateController
{
    /**
     * Erstellt eine neue Version für den Eintrag und liefert 200 mit versionStatus.
     *
     * Wirft ApiProblem 404 bei fehlendem oder gelöschtem Eintrag, 403 bei
     * fehlendem Eigentumsnachweis, 409 wenn der Eintrag hidden ist oder die
     * neue Versionsnummer nicht größer als die bisherige Maximalversion ist,
     * sowie 429 bei überschrittenem Rate-Limit.
     */
    #[Route('/api/v1/entries/{formatId}', methods: ['PUT'])]
    public function __invoke(
        string $formatId,
        Request $request,
        SubmissionService $submission,
        SubmitterResolver $resolver,
        PayloadAnalyzer $analyzer,
        EntryRepository $entries,
        EntryVersionRepository $versions,
        EntityManagerInterface $em,
        RateLimitGuard $guard,
        RateLimiterFactoryInterface $updateLimiter,
    ): JsonResponse {
        $guard->consume($updateLimiter, $request->getClientIp() ?? 'unknown');

        $entry = $entries->findOneBy(['formatId' => $formatId]);
        if ($entry === null || $entry->status === EntryStatus::Deleted) {
            throw new ApiProblem(404, 'Entry not found');
        }
        $resolver->requireOwner($request, $entry);
        if ($entry->status === EntryStatus::Hidden) {
            throw new ApiProblem(409, 'Entry is hidden pending moderation');
        }

        $meta = $submission->parseSubmissionBody($request, categoriesRequired: false);
        $result = $submission->validatePayload($meta['payloadJson'], $entry->type, $entry->formatId);
        $payload = $result->payload;

        $maxSemver = $versions->maxSemver($entry);
        if ($maxSemver !== null && !version_compare($payload['version'], $maxSemver, '>')) {
            throw new ApiProblem(409, 'Version must be greater than ' . $maxSemver);
        }
        $hash = $submission->assertNoDuplicate($payload, $entry);

        $version = new EntryVersion($entry, $payload['version'], $payload, $hash);
        $version->changelog = $meta['changelog'];
        $version->hasTransformCode = $analyzer->hasTransform($payload);

        if ($entry->status === EntryStatus::Pending) {
            // Sonderfall Spec: wartende pending-Version wird ersetzt, Entry bleibt pending
            foreach ($versions->findBy(['entry' => $entry, 'status' => VersionStatus::Pending]) as $old) {
                $em->remove($old);
            }
        } elseif ($version->hasTransformCode) {
            // Transform-Updates umgehen NIE die Warteschlange (Supply-Chain-Schutz)
        } else {
            $version->status = VersionStatus::Approved;
            $entry->currentVersion = $version;
            $submission->refreshDerived($entry, $payload);
        }

        $submission->applyMetadata($entry, $meta);
        $entry->touch();
        $em->persist($version);
        $em->flush();

        return new JsonResponse(['formatId' => $entry->formatId, 'versionStatus' => $version->status->value]);
    }
}
