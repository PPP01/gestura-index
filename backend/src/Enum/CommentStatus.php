<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Moderationsstatus des optionalen Freitext-Kommentars einer Bewertung (Phase 3 D).
 * Der Stern-Wert ist davon unabhängig und zählt immer sofort ins Aggregat.
 */
enum CommentStatus: string
{
    case Pending = 'pending';    // Kommentar in der Moderations-Warteschlange
    case Approved = 'approved';  // freigegeben (öffentlich in /reviews sichtbar)
    case Rejected = 'rejected';  // abgelehnt – Text ausgeblendet, Stern zählt weiter
}
