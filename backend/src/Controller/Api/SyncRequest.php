<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Api\SyncContract;
use App\Exception\SyncProblem;
use Symfony\Component\HttpFoundation\Request;

/**
 * Das gemeinsame Prüfwerk der vier Locator-Sync-Endpunkte: JSON auspacken,
 * apiLevel, Locator, stateId und die beiden Envelopes prüfen. Vier
 * Endpunkte, eine Prüfung – die Reihenfolge (Form prüfen, BEVOR der Wert
 * etwas adressiert) ist Vertrag und steht deshalb an genau einer Stelle.
 *
 * Nichts in dieser Klasse loggt. Der Body trägt den Locator, und der ist ein
 * Bearer-Token: ein Log mit Bodies wäre ein Log voller Zugangsschlüssel.
 */
final class SyncRequest
{
    /**
     * @return array<string, mixed>
     *
     * @throws SyncProblem 400 bei kaputtem JSON oder fehlendem apiLevel
     */
    public static function parse(Request $request): array
    {
        try {
            $body = json_decode($request->getContent(), true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw SyncProblem::badRequest();
        }
        if (!\is_array($body)) {
            throw SyncProblem::badRequest();
        }
        // Tolerant geprüft: vorhanden und ganzzahlig, sonst nichts. Ein Client
        // unter Level 3 ruft diese Endpunkte nie auf, und ein künftiger
        // Level-4-Client darf nicht abgewiesen werden (Toleranzregel im Kopf
        // des Vertrags, genauso gehandhabt in UpdateCheckController).
        if (!\is_int($body['apiLevel'] ?? null)) {
            throw SyncProblem::badRequest();
        }

        return $body;
    }

    /**
     * Prüft die Locator-Form und liefert NUR den Hash – der Klartext verlässt
     * diese Methode nicht, damit er nirgends versehentlich in eine Query,
     * einen Pfad oder ein Log gerät.
     *
     * @param array<string, mixed> $body
     */
    public static function locatorHash(array $body): string
    {
        $locator = $body['locator'] ?? null;
        if (!\is_string($locator) || !preg_match(SyncContract::LOCATOR_REGEX, $locator)) {
            throw SyncProblem::badRequest();
        }

        return SyncContract::locatorHash($locator);
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return ($required is true ? string : ?string)
     */
    public static function stateId(array $body, bool $required): ?string
    {
        $stateId = $body['stateId'] ?? null;
        if ($stateId === null && !$required) {
            return null;
        }
        if (!\is_string($stateId) || !preg_match(SyncContract::STATE_ID_REGEX, $stateId)) {
            throw SyncProblem::badRequest();
        }

        return $stateId;
    }

    /**
     * Envelope prüfen: String, sauberes Base64, innerhalb seiner Grenze.
     * Gemessen wird die Länge WIE ÜBERTRAGEN, also die des Base64-Strings –
     * dieselbe Messung, die das »size«-Feld der Antworten meldet.
     *
     * @param array<string, mixed> $body
     */
    public static function envelope(array $body, string $key, int $maxBytes): string
    {
        $value = $body[$key] ?? null;
        if (!\is_string($value) || $value === '') {
            throw SyncProblem::badRequest();
        }
        // Grenze VOR dem Dekodieren: sonst dekodiert der Server erst 50 MiB,
        // um danach festzustellen, dass sie zu groß waren.
        if (\strlen($value) > $maxBytes) {
            throw SyncProblem::tooLarge();
        }
        if (SyncContract::decodeEnvelope($value) === null) {
            throw SyncProblem::badRequest();
        }

        return $value;
    }

    /**
     * Der optionale Schreib-Token. Fehlt er, wird bedingungslos geschrieben –
     * so entsteht ein neuer Stand, und so sagt ein Client nach einem Konflikt
     * »trotzdem überschreiben«.
     *
     * @param array<string, mixed> $body
     */
    public static function basePayloadHash(array $body): ?string
    {
        $hash = $body['basePayloadHash'] ?? null;
        if ($hash === null) {
            return null;
        }
        if (!\is_string($hash) || !preg_match(SyncContract::PAYLOAD_HASH_REGEX, $hash)) {
            throw SyncProblem::badRequest();
        }

        return $hash;
    }
}
