<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Feste Taxonomie-Kategorien für Einträge im Gestura-Index.
 * Wird über {@see \App\Entity\EntryCategory} mit einem Entry verknüpft;
 * ein Entry kann mehrere Kategorien besitzen.
 */
enum Category: string
{
    case Dev = 'dev';                       // Entwickler-Tools und Coding-Ressourcen
    case Shopping = 'shopping';
    case Video = 'video';
    case News = 'news';
    case Social = 'social';
    case Productivity = 'productivity';
    case Search = 'search';
    case Reference = 'reference';           // Nachschlagewerke und Dokumentation
    case Entertainment = 'entertainment';
    case Other = 'other';
}
