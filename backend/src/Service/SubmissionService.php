<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Entry;
use App\Enum\Category;
use App\Enum\EntryType;
use App\Exception\ApiProblem;
use App\Repository\EntryVersionRepository;
use Symfony\Component\HttpFoundation\Request;

final class SubmissionService
{
    private const BODY_MAX = 131072; // 128 KiB: blobMax + Metadaten-Puffer
    private const TAGS_MAX = 10;
    private const TAG_LENGTH_MAX = 50;
    private const CHANGELOG_MAX = 2000;

    public function __construct(
        private readonly ExchangeValidator $validator,
        private readonly PayloadAnalyzer $analyzer,
        private readonly EntryVersionRepository $versions,
    ) {
    }

    /**
     * @return array{payloadJson: string, categories: ?list<Category>, tags: ?list<string>,
     *               changelog: ?string, deprecated: ?bool, successorFormatId: ?string}
     */
    public function parseSubmissionBody(Request $request, bool $categoriesRequired = true): array
    {
        $raw = $request->getContent();
        if (\strlen($raw) > self::BODY_MAX) {
            throw new ApiProblem(413, 'Request body too large');
        }

        try {
            $body = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ApiProblem(400, 'Invalid JSON body');
        }
        if (!\is_array($body) || !\is_array($body['payload'] ?? null)) {
            throw new ApiProblem(400, 'Missing payload object');
        }

        $categories = null;
        if (\array_key_exists('categories', $body)) {
            if (!\is_array($body['categories'])) {
                throw new ApiProblem(400, 'categories must be a list');
            }
            $categories = [];
            foreach ($body['categories'] as $key) {
                $categories[] = \is_string($key)
                    ? (Category::tryFrom($key) ?? throw new ApiProblem(400, 'Unknown category: ' . $key))
                    : throw new ApiProblem(400, 'Unknown category');
            }
            $categories = array_values(array_unique($categories, SORT_REGULAR));
            if (\count($categories) < 1 || \count($categories) > 3) {
                throw new ApiProblem(400, 'Between 1 and 3 categories required');
            }
        }

        $tags = [];
        foreach ((array) ($body['tags'] ?? []) as $tag) {
            if (!\is_string($tag)) {
                throw new ApiProblem(400, 'tags must be strings');
            }
            $normalized = mb_strtolower(trim($tag));
            if ($normalized === '' || \in_array($normalized, $tags, true)) {
                continue;
            }
            if (mb_strlen($normalized) > self::TAG_LENGTH_MAX) {
                throw new ApiProblem(400, 'Tag too long');
            }
            $tags[] = $normalized;
        }
        if (\count($tags) > self::TAGS_MAX) {
            throw new ApiProblem(400, 'At most 10 tags allowed');
        }

        $changelog = $body['changelog'] ?? null;
        if ($changelog !== null && (!\is_string($changelog) || mb_strlen($changelog) > self::CHANGELOG_MAX)) {
            throw new ApiProblem(400, 'Invalid changelog');
        }

        $successor = $body['successorFormatId'] ?? null;
        if ($successor !== null && (!\is_string($successor) || mb_strlen($successor) > 128)) {
            throw new ApiProblem(400, 'Invalid successorFormatId');
        }

        return [
            'payloadJson' => json_encode($body['payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'categories' => $categories ?? ($categoriesRequired ? throw new ApiProblem(400, 'Between 1 and 3 categories required') : null),
            'tags' => \array_key_exists('tags', $body) ? $tags : null,
            'changelog' => $changelog,
            'deprecated' => isset($body['deprecated']) ? (bool) $body['deprecated'] : null,
            'successorFormatId' => $successor,
        ];
    }

    public function validatePayload(string $payloadJson, ?EntryType $expectedType, ?string $expectedFormatId): ValidationResult
    {
        $result = $this->validator->validate($payloadJson);
        if (!$result->ok) {
            throw new ApiProblem(400, 'Payload validation failed', ['errors' => $result->errors]);
        }
        if ($expectedType !== null && $result->type !== $expectedType->value) {
            throw new ApiProblem(400, 'Payload type does not match entry type');
        }
        if ($expectedFormatId !== null && ($result->payload['id'] ?? null) !== $expectedFormatId) {
            throw new ApiProblem(400, 'Payload id does not match entry formatId');
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return string der contentHash des Payloads
     */
    public function assertNoDuplicate(array $payload, ?Entry $ignoreEntry): string
    {
        $hash = $this->analyzer->contentHash($payload);
        foreach ($this->versions->findBy(['contentHash' => $hash]) as $existing) {
            if ($ignoreEntry === null || $existing->entry->id !== $ignoreEntry->id) {
                throw new ApiProblem(409, 'Identical content already exists in the index');
            }
        }

        return $hash;
    }

    /** @param array{categories: ?list<Category>, tags: ?list<string>, deprecated: ?bool, successorFormatId: ?string} $meta */
    public function applyMetadata(Entry $entry, array $meta): void
    {
        if ($meta['categories'] !== null) {
            $entry->setCategories($meta['categories']);
        }
        if ($meta['tags'] !== null) {
            $entry->tags = $meta['tags'];
        }
        if ($meta['deprecated'] !== null) {
            $entry->deprecated = $meta['deprecated'];
        }
        if ($meta['successorFormatId'] !== null) {
            $entry->successorFormatId = $meta['successorFormatId'];
        }
    }

    /** @param array<string, mixed> $payload */
    public function refreshDerived(Entry $entry, array $payload): void
    {
        $entry->domains = $this->analyzer->extractDomains($payload);
        $entry->searchText = $this->analyzer->searchText($payload);
    }
}
