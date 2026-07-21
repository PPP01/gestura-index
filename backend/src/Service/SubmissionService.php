<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Entry;
use App\Enum\Category;
use App\Enum\EntryStatus;
use App\Enum\EntryType;
use App\Enum\VersionStatus;
use App\Exception\ApiProblem;
use App\Repository\EntryVersionRepository;
use Symfony\Component\HttpFoundation\Request;

/**
 * Kapselt den gesamten Einreichungs-Workflow: Body-Parsing, Schema-Validierung,
 * Duplikatprüfung und das Übertragen von Metadaten sowie abgeleiteten Feldern
 * auf ein Entry-Objekt. Wirft ApiProblem bei fehlerhaften oder unzulässigen
 * Eingaben.
 */
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
     * Liest und validiert den Einreichungs-Body aus dem Request: prüft das
     * Größenlimit, dekodiert JSON, normalisiert Kategorien und Tags und gibt
     * das strukturierte Ergebnis zurück. Wirft ApiProblem 400/413 bei
     * ungültigen oder zu großen Eingaben.
     *
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

    /**
     * Validiert den Payload gegen das Exchange-Schema und prüft optional, ob
     * Typ und Format-ID mit dem bestehenden Eintrag übereinstimmen.
     * Wirft ApiProblem 400 bei Schema-Verstoß oder Typ-/ID-Konflikt.
     */
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
     * Stellt sicher, dass kein inhaltlich identischer Eintrag im Index existiert.
     * Abgelehnte Versionen und Versionen gelöschter Einträge blockieren keinen
     * Hash dauerhaft. Wirft ApiProblem 409 bei Duplikat.
     *
     * @param array<string, mixed> $payload
     *
     * @return string der contentHash des Payloads
     */
    public function assertNoDuplicate(array $payload, ?Entry $ignoreEntry): string
    {
        $hash = $this->analyzer->contentHash($payload);
        foreach ($this->versions->findBy(['contentHash' => $hash]) as $existing) {
            if ($ignoreEntry !== null && $existing->entry->id === $ignoreEntry->id) {
                continue;
            }
            // Abgelehnte Versionen und Versionen gelöschter Einträge
            // dürfen einen contentHash nicht dauerhaft blockieren —
            // sonst könnte eine abgelehnte Junk-Einreichung fremden
            // Content für immer "verbrennen".
            if ($existing->status === VersionStatus::Rejected || $existing->entry->status === EntryStatus::Deleted) {
                continue;
            }
            throw new ApiProblem(409, 'Identical content already exists in the index');
        }

        return $hash;
    }

    /**
     * Überträgt nicht-null-Werte aus $meta (Kategorien, Tags, deprecated,
     * successorFormatId) auf den Eintrag – null-Felder werden übersprungen,
     * damit partielle Updates nur die gesendeten Felder ändern.
     *
     * @param array{categories: ?list<Category>, tags: ?list<string>, deprecated: ?bool, successorFormatId: ?string} $meta
     */
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

    /**
     * Aktualisiert die abgeleiteten Felder des Eintrags (Domains und Suchtext)
     * aus dem dekodierten Payload – muss nach jeder Payload-Änderung aufgerufen
     * werden, damit Index und Volltext-Suche aktuell bleiben.
     *
     * @param array<string, mixed> $payload
     */
    public function refreshDerived(Entry $entry, array $payload): void
    {
        $entry->domains = $this->analyzer->extractDomains($payload);
        $entry->searchText = $this->analyzer->searchText($payload);
    }
}
