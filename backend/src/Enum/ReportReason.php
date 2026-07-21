<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Meldegrund für einen Report auf einen Entry.
 */
enum ReportReason: string
{
    case Spam = 'spam';
    case BrokenLinks = 'broken_links';  // mindestens ein Link im Eintrag ist nicht erreichbar
    case Misleading = 'misleading';     // irreführende oder falsche Beschreibung
    case Legal = 'legal';               // möglicher Rechtsverstoß (Urheberrecht, DSGVO o.ä.)
}
