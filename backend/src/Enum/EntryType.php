<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Typ-Unterscheidung der zwei Austauschformat-Varianten eines Index-Eintrags.
 */
enum EntryType: string
{
    case Menu = 'menu';       // Gestura-Menü (gesturaMenu: 1)
    case Engine = 'engine';   // Suchmaschinen- oder Link-Definition (gesturaEngine: 1)
}
