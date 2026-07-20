<?php

declare(strict_types=1);

namespace App\Enum;

enum ReportReason: string
{
    case Spam = 'spam';
    case BrokenLinks = 'broken_links';
    case Misleading = 'misleading';
    case Legal = 'legal';
}
