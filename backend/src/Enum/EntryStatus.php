<?php

declare(strict_types=1);

namespace App\Enum;

enum EntryStatus: string
{
    case Pending = 'pending';
    case Published = 'published';
    case Hidden = 'hidden';
    case Deleted = 'deleted';
}
