<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Api\SyncContract;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Sichert die Werte des Sync-Vertrags gegen die Testvektoren des Vertrags ab.
 * Stimmt hier etwas nicht, darf nichts weiter gebaut werden – der payloadHash
 * entscheidet darüber, ob ein fremder Schreibvorgang überschrieben wird.
 */
final class SyncContractTest extends TestCase
{
    /** Der Locator ist 32 Byte als Base64url ohne Padding – exakt 43 Zeichen. */
    public function testLocatorRegexAcceptsTheContractTestVector(): void
    {
        // Vertrag, »Derivation test vectors«, Geheimnis 0001…1f
        $locator = 'zoogXw2lwmt_ZqFnRu-lFOWYxyJaU2kpxfunpy3Umsk';

        self::assertSame(43, \strlen($locator));
        self::assertMatchesRegularExpression(SyncContract::LOCATOR_REGEX, $locator);
        // Und der zweite Vektor.
        self::assertMatchesRegularExpression(SyncContract::LOCATOR_REGEX, '3qzyS44KqXaBNzKvFSontDE8CfLPp8lwOUVHroaeg7M');
    }

    /**
     * Die Form wird geprüft, BEVOR sie etwas adressiert – sonst ist sie ein
     * Pfad-Traversal. Base64 mit Padding und Standard-Alphabet ist kein
     * Locator: »+«, »/« und »=« stehen nicht im Zeichenvorrat.
     */
    #[DataProvider('badLocators')]
    public function testLocatorRegexRejects(string $bad): void
    {
        self::assertDoesNotMatchRegularExpression(SyncContract::LOCATOR_REGEX, $bad);
    }

    public static function badLocators(): iterable
    {
        yield 'zu kurz' => [str_repeat('a', 42)];
        yield 'zu lang' => [str_repeat('a', 44)];
        yield 'Pfad-Traversal' => ['../../../../../../../../../../etc/passwd'];
        yield 'Schrägstrich' => [str_repeat('a', 42) . '/'];
        yield 'Plus' => [str_repeat('a', 42) . '+'];
        yield 'Padding' => [str_repeat('a', 42) . '='];
        yield 'Nullbyte' => [str_repeat('a', 42) . "\0"];
        yield 'Zeilenumbruch' => [str_repeat('a', 21) . "\n" . str_repeat('a', 21)];
        // /D-Modifier: $ matcht ohne D auch vor einem abschließenden \n
        yield 'abschließendes Newline' => [str_repeat('a', 43) . "\n"];
        yield 'leer' => [''];
    }

    // --- parseAtomTimestamp (Task 8, sync-ping-wartung) ---

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function atomTimestampProvider(): iterable
    {
        // --- gültig ---
        yield 'UTC +00:00' => ['2026-10-09T12:00:00+00:00', true];
        yield 'UTC Z' => ['2026-10-09T12:00:00Z', true];
        yield 'anderer Offset +02:00' => ['2026-10-09T14:00:00+02:00', true];
        yield 'Whitespace außen (wird getrimmt)' => [
            '  2026-10-09T12:00:00+00:00  ', true,
        ];

        // --- ungültig ---
        yield 'leer' => ['', false];
        yield 'nur Whitespace' => ['   ', false];
        yield 'fehlender Offset' => ['2026-10-09T12:00:00', false];
        yield 'unmögliches Datum (Feb 31)' => [
            '2026-02-31T12:00:00+00:00', false,
        ];
        yield 'natürliche Sprache (tomorrow)' => ['tomorrow', false];
        yield 'angehängter Müll' => [
            '2030-01-01T12:00:00+00:00junk', false,
        ];
        yield 'Nullbyte (PHP 8.5 ValueError)' => [
            "2030-01-01T12:00:00+00:00\x00junk", false,
        ];
    }

    #[DataProvider('atomTimestampProvider')]
    public function testParseAtomTimestamp(
        string $input,
        bool $expectValid,
    ): void {
        $result = SyncContract::parseAtomTimestamp($input);
        if ($expectValid) {
            self::assertInstanceOf(
                \DateTimeImmutable::class,
                $result,
                "Erwartet: gültig für »$input«",
            );
        } else {
            self::assertNull(
                $result,
                "Erwartet: null für »$input«",
            );
        }
    }

    /** +02:00 → UTC-normierte Ausgabe über formatTimestamp(). */
    public function testParseAtomTimestampNormalizesToUtc(): void
    {
        $dt = SyncContract::parseAtomTimestamp(
            '2026-10-09T14:00:00+02:00',
        );
        self::assertNotNull($dt);
        self::assertSame(
            '2026-10-09T12:00:00+00:00',
            SyncContract::formatTimestamp($dt),
        );
    }

    /** stateId ist clientseitig erzeugt: 16 Byte als Kleinbuchstaben-Hex. */
    public function testStateIdRegex(): void
    {
        self::assertMatchesRegularExpression(SyncContract::STATE_ID_REGEX, '0123456789abcdef0123456789abcdef');
        self::assertDoesNotMatchRegularExpression(SyncContract::STATE_ID_REGEX, '0123456789ABCDEF0123456789ABCDEF');
        self::assertDoesNotMatchRegularExpression(SyncContract::STATE_ID_REGEX, '0123456789abcdef0123456789abcde');
        self::assertDoesNotMatchRegularExpression(SyncContract::STATE_ID_REGEX, '../etc/passwd');
        // /D-Modifier: abschließendes \n darf nicht als gültig durchgehen
        self::assertDoesNotMatchRegularExpression(SyncContract::STATE_ID_REGEX, '0123456789abcdef0123456789abcdef' . "\n");
    }

    /**
     * payloadHash ist SHA-256 über die ROHEN Bytes des Envelopes – also über
     * das, was das Base64 dekodiert –, als Base64url ohne Padding. Der
     * Testvektor steht im Vertrag unter »Envelope test vector«.
     */
    public function testPayloadHashMatchesTheContractTestVector(): void
    {
        $envelope = 'AQIDBAUGBwgJCgsMhBezK2ZidsR4vw2Le+JA1vfSdGXw0lkopKj0PjhL9A==';

        self::assertSame('wTZSj7yLdniic9fTzg1YQgD4WVynX3BgPTYosChka2c', SyncContract::payloadHash($envelope));
        self::assertSame(43, \strlen(SyncContract::payloadHash($envelope)));
        self::assertMatchesRegularExpression(SyncContract::PAYLOAD_HASH_REGEX, SyncContract::payloadHash($envelope));
    }

    /** Der Locator wird gehasht abgelegt – 64 Zeichen Hex, kein Salz. */
    public function testLocatorHashIsPlainSha256Hex(): void
    {
        $locator = 'zoogXw2lwmt_ZqFnRu-lFOWYxyJaU2kpxfunpy3Umsk';

        self::assertSame(hash('sha256', $locator), SyncContract::locatorHash($locator));
        self::assertSame(64, \strlen(SyncContract::locatorHash($locator)));
    }

    /**
     * Strikt dekodieren: alles, was kein sauberes Base64 ist, ist bad-request
     * und darf nicht stillschweigend zu anderen Bytes werden.
     */
    public function testDecodeEnvelopeIsStrict(): void
    {
        self::assertSame("\x01\x02\x03", SyncContract::decodeEnvelope(base64_encode("\x01\x02\x03")));
        self::assertNull(SyncContract::decodeEnvelope('nicht base64!!'));
        self::assertNull(SyncContract::decodeEnvelope('AQID===='));
    }
}
