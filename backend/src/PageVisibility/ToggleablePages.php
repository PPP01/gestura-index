<?php

declare(strict_types=1);

namespace App\PageVisibility;

/**
 * Feste Whitelist der schaltbaren Marketing-Seiten (Slug = SvelteKit-Route
 * ohne Locale-Präfix). C1 (»/«) und der Katalog (»/index«) sind bewusst NICHT
 * enthalten – sie bleiben immer aktiv.
 */
final class ToggleablePages
{
    public const KEYS = ['was-ist-gestura', 'maus-gesten', 'vergleich', 'beispiele'];

    public static function isValid(string $key): bool
    {
        return \in_array($key, self::KEYS, true);
    }
}
