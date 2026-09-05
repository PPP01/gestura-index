<?php

declare(strict_types=1);

namespace App\Exception;

use Symfony\Component\HttpFoundation\Response;

/**
 * Eine Exception, die ihre eigene API-Antwort kennt.
 *
 * Der Regelfall auf /api/-Pfaden ist RFC 7807 »problem+json«, gerendert von
 * ProblemJsonSubscriber. Manche Endpunkte schulden ihrem Gegenüber aber eine
 * andere, vertraglich festgelegte Form – die Locator-Sync-Endpunkte etwa
 * `{ "error": "<code>" }`. Diese Schnittstelle hält den Subscriber davon frei,
 * einzelne Feature-Exceptions namentlich zu kennen: er fragt nach der Form,
 * statt die Klasse zu raten.
 */
interface RendersOwnApiResponse
{
    /** Die vollständige Antwort für diesen Fehler, inklusive Status und Headern. */
    public function toApiResponse(): Response;
}
