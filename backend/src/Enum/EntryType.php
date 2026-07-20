<?php

declare(strict_types=1);

namespace App\Enum;

enum EntryType: string
{
    case Menu = 'menu';
    case Engine = 'engine';
}
