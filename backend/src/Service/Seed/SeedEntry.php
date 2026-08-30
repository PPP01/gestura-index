<?php

declare(strict_types=1);

namespace App\Service\Seed;

use App\Enum\Category;
use App\Enum\EntryType;

/**
 * Ein kuratierter Katalog-Eintrag für den Basisstock (`index:seed`).
 *
 * Trägt den fertigen Exchange-Payload (identisch zu einer echten Einreichung)
 * plus die Index-Metadaten, die nicht Teil des portablen Formats sind
 * (Kategorien, Tags). `formatId` ist stets gleich `payload['id']`.
 */
final readonly class SeedEntry
{
    /**
     * @param array<string, mixed> $payload    vollständiger gesturaMenu-/gesturaEngine-Payload
     * @param list<Category>       $categories  1–3 Kategorien
     * @param list<string>         $tags        0–10 Tags (klein geschrieben)
     */
    public function __construct(
        public string $formatId,
        public EntryType $type,
        public string $semver,
        public array $payload,
        public array $categories,
        public array $tags = [],
    ) {
    }
}
