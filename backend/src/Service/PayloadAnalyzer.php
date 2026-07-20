<?php

declare(strict_types=1);

namespace App\Service;

final class PayloadAnalyzer
{
    /**
     * @param array<string, mixed> $payload
     *
     * @return list<string>
     */
    public function extractDomains(array $payload): array
    {
        $domains = [];
        foreach ((array) ($payload['patterns'] ?? []) as $pattern) {
            if (!\is_string($pattern)) {
                continue;
            }
            $candidate = strtolower(trim($pattern));
            $candidate = preg_replace('#^[a-z]+://#', '', $candidate) ?? '';
            $candidate = explode('/', $candidate, 2)[0];
            $candidate = trim($candidate, '*.');
            if ($candidate !== '' && str_contains($candidate, '.') && preg_match('/^[a-z0-9.-]+$/', $candidate)) {
                $domains[$candidate] = true;
            }
        }

        return array_keys($domains);
    }

    /** @param array<string, mixed> $payload */
    public function searchText(array $payload): string
    {
        $parts = [];
        foreach (['name', 'description'] as $field) {
            $value = $payload[$field] ?? null;
            if (\is_string($value)) {
                $parts[] = $value;
            } elseif (\is_array($value)) {
                foreach ($value as $translation) {
                    if (\is_string($translation)) {
                        $parts[] = $translation;
                    }
                }
            }
        }

        return mb_strtolower(implode(' ', $parts));
    }

    /** @param array<string, mixed> $payload */
    public function contentHash(array $payload): string
    {
        // id und version fließen bewusst NICHT in den Hash ein: die
        // Duplikat-Erkennung soll identischen Inhalt auch unter neuer
        // Kennung oder Versionsnummer erkennen.
        unset($payload['id'], $payload['version']);
        $this->ksortRecursive($payload);

        return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /** @param array<string, mixed> $payload */
    public function hasTransform(array $payload): bool
    {
        return ($payload['transformEnabled'] ?? false) === true
            && \is_string($payload['transformCode'] ?? null)
            && trim($payload['transformCode']) !== '';
    }

    private function ksortRecursive(array &$value): void
    {
        // Nur String-Key-Maps sortieren — Listen (items!) behalten ihre Reihenfolge.
        if (!array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as &$child) {
            if (\is_array($child)) {
                $this->ksortRecursive($child);
            }
        }
    }
}
