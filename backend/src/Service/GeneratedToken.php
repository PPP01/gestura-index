<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Wertobjekt, das das Ergebnis eines generierten Capability-Tokens transportiert
 * (Edit-Token »gsti_«, Invite »gsta_« oder Konto-Token »gacc_«). token ist der
 * Klartext zur einmaligen Ausgabe an den Client; selector und hash werden in der
 * Datenbank gespeichert – das Klartext-Token selbst nie.
 */
final readonly class GeneratedToken
{
    /**
     * @param string $token    Klartext-Token zur Ausgabe (»<präfix>_<selector>_<verifier>«)
     * @param string $selector Lookup-Schlüssel in der Datenbank (16 Hex-Zeichen)
     * @param string $hash     Argon2id-Hash des Verifiers zur späteren Verifikation
     */
    public function __construct(
        public string $token,
        public string $selector,
        public string $hash,
    ) {
    }
}
