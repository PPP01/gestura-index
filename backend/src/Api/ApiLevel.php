<?php

declare(strict_types=1);

namespace App\Api;

/**
 * Der apiLevel, den dieser Index gegenüber der Extension umsetzt (Vertrag
 * docs/gestura-eu-api.md). Die Level sind additiv: 2 = Update-Check
 * (/api/v1/updates), 3 = zusätzlich die /api/v1/sync/*-Endpunkte.
 *
 * Genau EINE Stelle für die Zahl: jede Antwort, die einen apiLevel trägt,
 * liest sie hier. Das R3-Paket hebt den Wert auf 3, sobald die vier
 * Sync-Endpunkte antworten – nicht früher, sonst verspräche der Index ein
 * Level, das er nicht bedient.
 */
final class ApiLevel
{
    public const IMPLEMENTED = 2;
}
