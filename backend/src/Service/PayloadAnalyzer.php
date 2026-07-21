<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Analysiert freigegebene Payload-Arrays auf Inhalte, die für Indexierung,
 * Duplikat-Erkennung und Supply-Chain-Schutz (transformCode) benötigt werden.
 */
final class PayloadAnalyzer
{
    /**
     * Extrahiert normalisierte Domain-Namen aus den URL-Patterns eines Eintrags
     * (Scheme, Wildcards und Pfade werden bereinigt).
     *
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

    /**
     * Erstellt einen normalisierten Volltext-Suchstring aus name und description
     * des Payloads (unterstützt sowohl String- als auch i18n-Objekt-Felder).
     *
     * @param array<string, mixed> $payload
     */
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

    /**
     * Berechnet einen SHA-256-Hash des inhaltsdefinierten Payloads zur Duplikat-Erkennung.
     * id und version werden bewusst ausgeschlossen (Details: Inline-Kommentar).
     *
     * @param array<string, mixed> $payload
     */
    public function contentHash(array $payload): string
    {
        // id und version fließen bewusst NICHT in den Hash ein: die
        // Duplikat-Erkennung soll identischen Inhalt auch unter neuer
        // Kennung oder Versionsnummer erkennen.
        unset($payload['id'], $payload['version']);
        $this->ksortRecursive($payload);

        return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /**
     * Prüft, ob der Payload einen aktiven transformCode enthält.
     * Einreichungen, bei denen dies true ergibt, müssen unabhängig von Trust-Level
     * und Update-Pfad immer in die Moderations-Warteschlange (Supply-Chain-Schutz).
     *
     * @param array<string, mixed> $payload
     */
    public function hasTransform(array $payload): bool
    {
        return ($payload['transformEnabled'] ?? false) === true
            && \is_string($payload['transformCode'] ?? null)
            && trim($payload['transformCode']) !== '';
    }

    /**
     * Sortiert rekursiv alle assoziativen Arrays nach Schlüssel für eine kanonische
     * JSON-Darstellung; Listen (array_is_list) behalten ihre Reihenfolge.
     *
     * @param array<string, mixed> $value
     */
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
