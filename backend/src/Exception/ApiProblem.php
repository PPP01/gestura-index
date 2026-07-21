<?php

declare(strict_types=1);

namespace App\Exception;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Typisierte HttpException für strukturierte API-Fehler – transportiert
 * neben HTTP-Statuscode und Titel optionale Zusatzfelder, die
 * ProblemJsonSubscriber in die RFC-7807-Antwort einmischt.
 */
final class ApiProblem extends HttpException
{
    /**
     * Erstellt ein neues ApiProblem mit HTTP-Statuscode, Titel und optionalen
     * RFC-7807-Zusatzfeldern (z. B. errors, invalid-params).
     *
     * @param array<string, mixed>  $extra   zusätzliche problem+json-Felder (z. B. errors-Liste)
     * @param array<string, string> $headers
     */
    public function __construct(
        int $statusCode,
        string $title,
        public readonly array $extra = [],
        array $headers = [],
    ) {
        parent::__construct($statusCode, $title, null, $headers);
    }
}
