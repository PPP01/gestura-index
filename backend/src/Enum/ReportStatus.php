<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Bearbeitungsstatus eines Reports durch die Moderation.
 */
enum ReportStatus: string
{
    case Open = 'open';         // noch unbearbeitet
    case Resolved = 'resolved'; // abgeschlossen (behoben oder abgelehnt)
}
