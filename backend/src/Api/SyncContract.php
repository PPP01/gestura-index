<?php

declare(strict_types=1);

namespace App\Api;

/**
 * Die Werte des Sync-Teils des Extension-Vertrags (autoritativ:
 * /mnt/c/Programme.alt/Gestura/docs/gestura-eu-api.md, Abschnitte »Sync — …«).
 * Jede Zahl und jede Form steht hier GENAU EINMAL – Controller, Service und
 * Tests lesen sie von hier, damit eine Vertragsänderung eine Zeile ist.
 *
 * Vorbild ist App\Api\ExchangeFormat für das Austauschformat.
 */
final class SyncContract
{
    /**
     * 32 Bytes als Base64url ohne Padding – exakt 43 Zeichen. Der Vertrag
     * kennt zwei Werte dieser Form, und sie tragen unten eigene Namen, weil
     * sie unabhängig voneinander geändert werden könnten. Das Literal steht
     * trotzdem nur einmal: eine Korrektur am Zeichenvorrat soll nicht an der
     * zweiten Stelle stillschweigend liegen bleiben.
     */
    private const BASE64URL_32_BYTES = '/^[A-Za-z0-9_-]{43}$/';

    /**
     * Der Locator. Diese Form wird geprüft, BEVOR der Wert etwas adressiert –
     * ungeprüft wäre sie ein Pfad-Traversal.
     */
    public const LOCATOR_REGEX = self::BASE64URL_32_BYTES;

    /** Der payloadHash bzw. der basePayloadHash: SHA-256 als Base64url. */
    public const PAYLOAD_HASH_REGEX = self::BASE64URL_32_BYTES;

    /** stateId: clientseitig erzeugte 16 Zufallsbytes als Kleinbuchstaben-Hex. */
    public const STATE_ID_REGEX = '/^[0-9a-f]{32}$/';

    /**
     * Die Grenzen messen den Envelope »wie übertragen«, also die Länge des
     * Base64-Strings – nicht die der dekodierten Bytes. Dieselbe Messung
     * liefert das »size«-Feld der Antworten.
     */
    public const MAX_META_BYTES = 8 * 1024;
    public const MAX_PAYLOAD_BYTES = 512 * 1024;

    /**
     * Stände pro Locator. Wird NUR beim Anlegen geprüft, nie rückwirkend:
     * ein Locator, der beim Herabsetzen der Grenze schon mehr Stände hatte,
     * behält sie alle. Die 4-MiB-Summe des Vertrags wird bewusst nicht
     * durchgesetzt – mit Per-Stand-Grenzen ist sie konstruktiv unerreichbar,
     * und einen Fehlercode dafür gibt es nicht.
     */
    public const MAX_STATES_PER_LOCATOR = 5;

    /** Aufbewahrung: 12 Monate ohne Lesen oder Schreiben (Vertrag, »Retention«). */
    public const RETENTION_DAYS = 365;

    /**
     * Granularität des mengenbasierten Per-IP-Limits: ein Token je
     * angefangenem KiB. Die Gegenstelle ist die Größenordnung der Limiter
     * »sync_v1_bytes« in config/packages/rate_limiter.yaml – wer hier
     * umstellt, muss dort mitziehen, deshalb steht die Zahl neben ihrer
     * Umrechnung und nicht in einem Controller-Ausdruck.
     */
    public const BYTES_PER_TOKEN = 1024;

    /** Tokens für eine geschriebene Menge – angefangene Einheiten zählen voll. */
    public static function tokensFor(int $bytes): int
    {
        return (int) ceil($bytes / self::BYTES_PER_TOKEN);
    }

    /**
     * Der Locator wird gehasht abgelegt und über den Hash nachgeschlagen:
     * Zugriff auf die Datenbank darf nicht das Recht bedeuten, fremde Stände
     * zu listen oder zu löschen. Kein Salz – der Locator sind 256
     * gleichverteilte Bits, es gibt nichts zu erraten, und ein Salz würde den
     * Lookup über den Hash unmöglich machen.
     */
    public static function locatorHash(string $locator): string
    {
        return hash('sha256', $locator);
    }

    /**
     * SHA-256 über die ROHEN Bytes des Envelopes (das, was das Base64
     * dekodiert), als Base64url ohne Padding – derselbe Wert, den der
     * Meta-Blob des Standes trägt und den »basePayloadHash« vergleicht.
     *
     * Vorbedingung: gültiges Base64. Auf dem Anfragepfad stellt das
     * LocatorSyncRequest::envelope() sicher (400 sonst); ein ungültiger Wert
     * hier ist ein Programmierfehler und wird laut, nicht still zum Hash des
     * leeren Strings.
     */
    public static function payloadHash(string $envelopeBase64): string
    {
        $raw = self::decodeEnvelope($envelopeBase64);
        if ($raw === null) {
            throw new \InvalidArgumentException('payloadHash erwartet gültiges Base64');
        }

        return self::base64Url(hash('sha256', $raw, true));
    }

    /** Base64url ohne Padding – die Kodierung, in der der Vertrag Hashes nennt. */
    public static function base64Url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    /**
     * Strikt dekodiertes Base64 oder null. Strikt, weil ein toleranter
     * Decoder Müll stillschweigend zu anderen Bytes macht – und der
     * payloadHash darüber entscheidet, ob ein fremder Schreibvorgang
     * überschrieben wird.
     */
    public static function decodeEnvelope(string $value): ?string
    {
        $raw = base64_decode($value, true);

        return $raw === false ? null : $raw;
    }
}
