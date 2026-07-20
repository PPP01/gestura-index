<?php

declare(strict_types=1);

namespace App\Exception;

use Symfony\Component\HttpKernel\Exception\HttpException;

final class ApiProblem extends HttpException
{
    /**
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
