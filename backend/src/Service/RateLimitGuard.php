<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\ApiProblem;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Zentrale Hilfsmethode zur Rate-Limit-Durchsetzung: kapselt den
 * Symfony-RateLimiter und wirft bei Erschöpfung einen 429er mit
 * Retry-After-Angabe.
 */
final class RateLimitGuard
{
    /**
     * Verbraucht $tokens des Rate-Limiters für den angegebenen Schlüssel und
     * wirft bei Erschöpfung 429 mit dem Header »Retry-After« in Sekunden.
     *
     * $tokens > 1 dient dem mengenbasierten Limit (etwa geschriebene KiB),
     * nicht der Anzahl der Anfragen – der Vertrag der Extension verlangt für
     * den Locator-Sync ausdrücklich beides.
     *
     * $onExhausted erlaubt einem Endpunkt, eine eigene Fehlerform zu liefern
     * (die Sync-Endpunkte schulden dem Client { "error": "rate-limited" }).
     * Ohne das Argument bleibt es beim RFC-7807-Standard dieser API – der
     * Guard selbst kennt damit nur eine Fehlerform und muss beim Hinzukommen
     * einer weiteren nicht angefasst werden.
     *
     * @param ?\Closure(int): \Throwable $onExhausted erhält retryAfter in Sekunden
     */
    public function consume(
        RateLimiterFactoryInterface $factory,
        string $key,
        int $tokens = 1,
        ?\Closure $onExhausted = null,
    ): void {
        $limit = $factory->create($key)->consume($tokens);
        if ($limit->isAccepted()) {
            return;
        }

        $retryAfter = max(1, $limit->getRetryAfter()->getTimestamp() - time());

        throw $onExhausted !== null
            ? $onExhausted($retryAfter)
            : new ApiProblem(429, 'Rate limit exceeded', ['retryAfter' => $retryAfter], ['Retry-After' => (string) $retryAfter]);
    }
}
