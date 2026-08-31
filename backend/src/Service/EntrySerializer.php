<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Entry;
use App\Entity\EntryVersion;

/**
 * Serialisiert Entry-Entities in API-konforme Arrays für Listen- und Detailansichten.
 * Greift auf currentVersion->payload zu und baut daraus ein öffentlich-stabiles Format.
 */
final class EntrySerializer
{
    /**
     * Gibt die kompakte Listenansicht eines Eintrags zurück.
     * screenshotUrl verweist auf den statusgeprüften GET-Endpunkt (null wenn
     * kein Screenshot vorhanden) – nie auf eine direkte Docroot-Datei.
     *
     * itemCount gehört bewusst in die LISTE und nicht erst in die Detailsicht:
     * ohne das Feld müsste die Website den Payload jedes einzelnen Eintrags
     * laden, nur um »6 Einträge« anzuzeigen – ein Request pro Karte. Hier kostet
     * es nichts, der Payload liegt bereits geladen vor.
     *
     * @return array<string, mixed>
     */
    public function toListItem(Entry $entry, ?array $rating = null): array
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
            'itemCount' => $this->itemCount($payload),
            'installCount' => $entry->installCount,
            'rating' => $rating ?? ['average' => null, 'count' => 0],
            'currentVersion' => $entry->currentVersion?->semver,
            'deprecated' => $entry->deprecated,
            'successorFormatId' => $entry->successorFormatId,
            'screenshotUrl' => $entry->screenshotPath === null ? null : '/api/v1/entries/' . $entry->formatId . '/screenshot',
            'updatedAt' => $entry->updatedAt->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * Zählt die Einträge eines Menü-Payloads.
     *
     * Gibt null zurück, wenn der Payload keine Item-Liste trägt – das ist bei
     * jeder Suchmaschine der Normalfall und keine Ausnahme.
     *
     * @param array<string, mixed> $payload
     */
    private function itemCount(array $payload): ?int
    {
        return \is_array($payload['items'] ?? null) ? \count($payload['items']) : null;
    }

    /**
     * Gibt die vollständige Detailansicht zurück – enthält alle Felder von toListItem()
     * ergänzt um die Versionsliste mit Changelog und hasTransformCode-Flag.
     *
     * @param list<EntryVersion> $versions freigegebene Versionen, neueste zuerst
     *
     * @return array<string, mixed>
     */
    public function toDetail(Entry $entry, array $versions, ?array $rating = null): array
    {
        return $this->toListItem($entry, $rating) + [
            'versions' => array_map(static fn (EntryVersion $v): array => [
                'semver' => $v->semver,
                'changelog' => $v->changelog,
                'hasTransformCode' => $v->hasTransformCode,
                'submittedAt' => $v->submittedAt->format(\DateTimeInterface::ATOM),
            ], $versions),
        ];
    }
}
