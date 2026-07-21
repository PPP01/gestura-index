<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Moderations- und Sichtbarkeitsstatus eines Entry im Lebenszyklus des Index.
 * Invariante: Pending impliziert currentVersion === null;
 * Published impliziert currentVersion !== null.
 */
enum EntryStatus: string
{
    case Pending = 'pending';       // in der Moderations-Warteschlange, noch nicht öffentlich
    case Published = 'published';   // öffentlich sichtbar; currentVersion ist gesetzt
    case Hidden = 'hidden';         // vom Betreiber ausgeblendet, nicht öffentlich
    case Deleted = 'deleted';       // Soft-Delete – aus dem Index entfernt
}
