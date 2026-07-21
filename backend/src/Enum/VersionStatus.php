<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Moderationsstatus einer einzelnen EntryVersion.
 */
enum VersionStatus: string
{
    case Pending = 'pending';   // in der Moderations-Warteschlange
    case Approved = 'approved'; // freigegeben und aktiv
    case Rejected = 'rejected'; // abgelehnt
}
