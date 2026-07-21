<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Unveränderliches Ergebnis-Value-Object der Exchange-Schema-Validierung:
 * enthält den Validierungsstatus, den erkannten Payload-Typ (menu|engine),
 * eine Liste von Fehlermeldungen und den dekodierten Payload.
 */
final readonly class ValidationResult
{
    /**
     * Erstellt ein Validierungsergebnis mit allen Feldern; für den Fehlerfall
     * steht die statische Methode fail() zur Verfügung.
     *
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

    /**
     * Erstellt ein fehlgeschlagenes Validierungsergebnis ohne Payload.
     *
     * @param list<string> $errors
     */
    public static function fail(?string $type, array $errors): self
    {
        return new self(false, $type, $errors, null);
    }
}
