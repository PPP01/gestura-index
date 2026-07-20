<?php

declare(strict_types=1);

namespace App\Service;

final readonly class ValidationResult
{
    /**
     * @param list<string>              $errors
     * @param array<string, mixed>|null $payload
     */
    public function __construct(
        public bool $ok,
        public ?string $type,
        public array $errors,
        public ?array $payload,
    ) {
    }

    /** @param list<string> $errors */
    public static function fail(?string $type, array $errors): self
    {
        return new self(false, $type, $errors, null);
    }
}
