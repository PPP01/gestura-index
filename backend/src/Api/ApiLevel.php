<?php

declare(strict_types=1);

namespace App\Api;

/**
 * Der apiLevel, den dieser Index gegenüber der Extension umsetzt (Vertrag
 * docs/gestura-eu-api.md). Die Level sind additiv: 2 = Update-Check
 * (/api/v1/updates), 3 = zusätzlich die /api/v1/sync/*-Endpunkte.
 *
 * Genau EINE Stelle für die Zahl: jede Antwort, die einen apiLevel trägt,
 * liest sie hier. Der Wert stand bis zum 2026-09-05 auf 2 und wurde erst
 * gehoben, nachdem die vier Sync-Endpunkte tatsächlich antworten – ein Level
 * ohne Deckung wäre schlimmer als ein zu niedriges, weil der Client sein
 * Verhalten daran ausrichtet. ApiLevelTest hält Zahl und Deckung zusammen.
 */
final class ApiLevel
{
    public const IMPLEMENTED = 3;
}
