<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Entry;
use App\Entity\EntryVersion;

final class EntrySerializer
{
    /** @return array<string, mixed> */
    public function toListItem(Entry $entry): array
    {
        $payload = $entry->currentVersion?->payload ?? [];

        return [
            'formatId' => $entry->formatId,
            'type' => $entry->type->value,
            'name' => $payload['name'] ?? $entry->formatId,
            'description' => $payload['description'] ?? null,
            'categories' => $entry->categoryKeys(),
            'tags' => $entry->tags,
            'domains' => $entry->domains,
            'installCount' => $entry->installCount,
            'currentVersion' => $entry->currentVersion?->semver,
            'deprecated' => $entry->deprecated,
            'successorFormatId' => $entry->successorFormatId,
            'screenshotUrl' => $entry->screenshotPath === null ? null : '/' . $entry->screenshotPath,
            'updatedAt' => $entry->updatedAt->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * @param list<EntryVersion> $versions freigegebene Versionen, neueste zuerst
     *
     * @return array<string, mixed>
     */
    public function toDetail(Entry $entry, array $versions): array
    {
        return $this->toListItem($entry) + [
            'versions' => array_map(static fn (EntryVersion $v): array => [
                'semver' => $v->semver,
                'changelog' => $v->changelog,
                'hasTransformCode' => $v->hasTransformCode,
                'submittedAt' => $v->submittedAt->format(\DateTimeInterface::ATOM),
            ], $versions),
        ];
    }
}
