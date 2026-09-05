<?php

declare(strict_types=1);

namespace App\Exception;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Fehler der Locator-Sync-Endpunkte in der Form, die der Extension-Vertrag
 * wörtlich vorschreibt: { "error": "<code>" }, ausgeliefert als
 * application/json – nicht als RFC-7807-problem+json wie der Rest der API.
 *
 * Wichtig aus dem Vertrag (»How the client reads an answer«): der Client
 * bildet die Codes ALLEIN über den HTTP-Status ab und liest den error-String
 * nie. Er liest genau einen Body – den des 412 – und daraus nur updatedAt.
 * Der Status ist also die tragende Angabe; der Body ist Dokumentation, mit
 * der einen Ausnahme.
 *
 * Konstruktor und Felder ähneln ApiProblem absichtlich, ohne davon zu erben:
 * die beiden Antwortformen sollen sich nicht gegenseitig einschränken, und
 * ein gemeinsamer Vorfahr würde die instanceof-Prüfungen mehrdeutig machen.
 * Die Form selbst liefert diese Klasse über RendersOwnApiResponse – der
 * ProblemJsonSubscriber muss sie dafür nicht kennen.
 */
final class SyncProblem extends HttpException implements RendersOwnApiResponse
{
    /**
     * @param array<string, mixed>  $extra   zusätzliche Felder neben »error«
     * @param array<string, string> $headers
     */
    private function __construct(
        int $statusCode,
        public readonly string $errorCode,
        public readonly array $extra = [],
        array $headers = [],
    ) {
        parent::__construct($statusCode, $errorCode, null, $headers);
    }

    /**
     * Die vertragsexakte Antwort: { "error": "<code>" } als application/json,
     * beim Konflikt zusätzlich updatedAt.
     */
    public function toApiResponse(): Response
    {
        return new JsonResponse(['error' => $this->errorCode] + $this->extra, $this->getStatusCode(), $this->getHeaders());
    }

    /** Kaputter Body, unbekanntes apiLevel, falsche stateId- oder Locator-Form. */
    public static function badRequest(): self
    {
        return new self(400, 'bad-request');
    }

    /** Kein solcher Stand unter diesem Locator. */
    public static function notFound(): self
    {
        return new self(404, 'not-found');
    }

    /**
     * basePayloadHash beschreibt den gespeicherten Stand nicht – jemand
     * anderes hat zuerst geschrieben. updatedAt reist mit, damit der Client
     * ohne zweite Anfrage sagen kann, WANN sich der Stand unter ihm geändert
     * hat. Es ist das einzige Feld, das der Client aus einem Fehler-Body liest.
     */
    public static function conflict(\DateTimeImmutable $updatedAt): self
    {
        return new self(412, 'conflict', ['updatedAt' => $updatedAt->format(\DateTimeInterface::ATOM)]);
    }

    /** Ein einzelner Blob überschreitet seine Grenze. */
    public static function tooLarge(): self
    {
        return new self(413, 'too-large');
    }

    /** Der Locator hält bereits die Höchstzahl an Ständen (nur beim Anlegen). */
    public static function quotaStates(): self
    {
        return new self(409, 'quota-states');
    }

    /** Per-IP-Grenze auf Anfragen oder auf geschriebene Bytes erschöpft. */
    public static function rateLimited(int $retryAfter): self
    {
        return new self(429, 'rate-limited', [], ['Retry-After' => (string) $retryAfter]);
    }
}
