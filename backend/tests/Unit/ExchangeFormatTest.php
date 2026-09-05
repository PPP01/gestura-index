<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Api\ExchangeFormat;
use PHPUnit\Framework\TestCase;

/**
 * Bindet die Konstanten-Kopien in ExchangeFormat an ihre Quelle
 * schema/exchange-schema.json: Driftet eine Seite, bricht dieser Test.
 */
final class ExchangeFormatTest extends TestCase
{
    public function testConstantsMatchTheSchema(): void
    {
        $schema = json_decode(
            (string) file_get_contents(\dirname(__DIR__, 3) . '/schema/exchange-schema.json'),
            true, 32, JSON_THROW_ON_ERROR,
        );

        foreach (['menu', 'engine'] as $type) {
            $id = $schema['$defs'][$type]['properties']['id'];
            self::assertSame('^' . ExchangeFormat::ID_PATTERN . '$', $id['pattern'], "$type.id.pattern");
            self::assertSame(ExchangeFormat::ID_MAX_LENGTH, $id['maxLength'], "$type.id.maxLength");
            self::assertSame(
                '^' . ExchangeFormat::SEMVER_PATTERN . '$',
                $schema['$defs'][$type]['properties']['version']['pattern'],
                "$type.version.pattern",
            );
        }
    }
}
