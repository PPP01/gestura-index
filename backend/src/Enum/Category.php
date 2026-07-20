<?php

declare(strict_types=1);

namespace App\Enum;

enum Category: string
{
    case Dev = 'dev';
    case Shopping = 'shopping';
    case Video = 'video';
    case News = 'news';
    case Social = 'social';
    case Productivity = 'productivity';
    case Search = 'search';
    case Reference = 'reference';
    case Entertainment = 'entertainment';
    case Other = 'other';
}
