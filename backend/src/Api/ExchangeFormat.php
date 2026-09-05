<?php

declare(strict_types=1);

namespace App\Api;

/**
 * Die Formatregeln des Austauschformats (schema/exchange-schema.json), die
 * AUSSERHALB der Schema-Validierung gebraucht werden: als Route-Requirement,
 * als Eingabeprüfung im Update-Check, als Längengrenze bei Einreichungen.
 *
 * Es sind Kopien aus dem Schema – Attribute können kein JSON lesen. Damit die
 * Kopie nicht still vom Original abdriftet, prüft ExchangeFormatTest jede
 * Konstante gegen die Schema-Datei. Wer hier ändert, ändert im Schema (im
 * Extension-Repo) zuerst und kopiert dann herüber.
 */
final class ExchangeFormat
{
    /** Kennungsmuster ohne Anker, für Route-Requirements; mit Ankern: ID_REGEX. */
    public const ID_PATTERN = '[a-zA-Z0-9]([a-zA-Z0-9._-]*[a-zA-Z0-9])?';
    public const ID_MAX_LENGTH = 128;
    /** Numerisches Tripel ohne Anker, für Route-Requirements; mit Ankern: SEMVER_REGEX. */
    public const SEMVER_PATTERN = '\d{1,5}\.\d{1,5}\.\d{1,5}';

    /** Fertige PCRE mit Ankern und Delimitern, direkt für preg_match(). */
    public const ID_REGEX = '/^' . self::ID_PATTERN . '$/';
    public const SEMVER_REGEX = '/^' . self::SEMVER_PATTERN . '$/';
}
