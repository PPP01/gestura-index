<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Enum\Category;
use App\Enum\EntryType;
use App\Service\ExchangeValidator;
use App\Service\Seed\SeedCatalog;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Qualitäts-Gate für den Basisstock: JEDER Katalog-Payload muss dieselbe
 * Validierung bestehen wie eine echte Einreichung, IDs sind eindeutig,
 * Metadaten halten die Submit-Limits ein, und der Katalog erreicht die
 * geforderte Größe (200+).
 */
final class SeedCatalogTest extends KernelTestCase
{
    private SeedCatalog $catalog;
    private ExchangeValidator $validator;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->catalog = self::getContainer()->get(SeedCatalog::class);
        $this->validator = self::getContainer()->get(ExchangeValidator::class);
    }

    public function testCatalogHasAtLeast200Entries(): void
    {
        self::assertGreaterThanOrEqual(200, \count($this->catalog->entries()));
    }

    public function testCatalogContainsBothEnginesAndMenus(): void
    {
        $types = array_map(static fn ($e) => $e->type, $this->catalog->entries());
        self::assertContains(EntryType::Engine, $types);
        self::assertContains(EntryType::Menu, $types);
    }

    public function testEveryPayloadPassesTheExchangeValidator(): void
    {
        foreach ($this->catalog->entries() as $entry) {
            $json = json_encode($entry->payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $result = $this->validator->validate($json);

            self::assertTrue(
                $result->ok,
                sprintf('%s ist ungültig: %s', $entry->formatId, implode(', ', $result->errors)),
            );
            self::assertSame($entry->type->value, $result->type, $entry->formatId);
        }
    }

    public function testFormatIdMatchesPayloadIdAndIsUnique(): void
    {
        $seen = [];
        foreach ($this->catalog->entries() as $entry) {
            self::assertSame($entry->payload['id'], $entry->formatId, 'formatId muss payload[id] entsprechen');
            self::assertArrayNotHasKey($entry->formatId, $seen, 'formatId doppelt: ' . $entry->formatId);
            $seen[$entry->formatId] = true;
        }
    }

    public function testNameMapsAreNotPointlessDuplicates(): void
    {
        // Ein mehrsprachiger Name mit identischen Werten (z. B. {en:X, de:X})
        // ist Unsinn – sprach-/regionsspezifische Einträge tragen genau ihre
        // Sprache, universelle bleiben ein einfacher String (= multilanguage).
        foreach ($this->catalog->entries() as $entry) {
            $name = $entry->payload['name'];
            if (\is_array($name) && \count($name) > 1) {
                self::assertGreaterThan(
                    1,
                    \count(array_unique($name)),
                    'Mehrsprachiger Name mit identischen Werten: ' . $entry->formatId,
                );
            }
        }
    }

    public function testMetadataRespectsSubmissionLimits(): void
    {
        foreach ($this->catalog->entries() as $entry) {
            $count = \count($entry->categories);
            self::assertGreaterThanOrEqual(1, $count, $entry->formatId);
            self::assertLessThanOrEqual(3, $count, $entry->formatId);
            foreach ($entry->categories as $category) {
                self::assertInstanceOf(Category::class, $category);
            }

            self::assertLessThanOrEqual(10, \count($entry->tags), $entry->formatId);
            foreach ($entry->tags as $tag) {
                self::assertLessThanOrEqual(50, mb_strlen($tag), $entry->formatId);
                self::assertSame(mb_strtolower($tag), $tag, 'Tags müssen klein geschrieben sein: ' . $tag);
            }
            self::assertSame(array_values(array_unique($entry->tags)), $entry->tags, 'Tags dürfen sich nicht wiederholen: ' . $entry->formatId);
        }
    }
}
