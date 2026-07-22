<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Erzeugt und prüft Invite-Tokens für die Einladung neuer Admin-Nutzer.
 * Das Klartext-Token wird der eingeladenen Person einmalig ausgehändigt und
 * nie gespeichert; persistiert werden nur Selector und Argon2id-Hash des
 * Verifiers. Spiegelt EditTokenService, Prefix »gsta_«.
 */
final class InviteTokenService
{
    private const PATTERN = '/^gsta_([0-9a-f]{16})_([A-Za-z0-9_-]{43})$/';

    /**
     * Erstellt ein neues Invite-Token und gibt Klartext-Token, Selector und
     * Argon2id-Hash des Verifiers als GeneratedInvite-Objekt zurück.
     * Das Token-Format ist »gsta_<selector16hex>_<verifier43base64url>«.
     */
    public function generate(): GeneratedInvite
    {
        $selector = bin2hex(random_bytes(8));
        $verifier = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        return new GeneratedInvite(
            token: sprintf('gsta_%s_%s', $selector, $verifier),
            selector: $selector,
            hash: password_hash($verifier, PASSWORD_ARGON2ID),
        );
    }

    /**
     * Zerlegt ein Invite-Token in Selector und Verifier. Gibt null zurück,
     * wenn das Token-Format nicht dem erwarteten Muster entspricht.
     *
     * @return array{selector: string, verifier: string}|null
     */
    public function parse(string $token): ?array
    {
        if (preg_match(self::PATTERN, $token, $m) !== 1) {
            return null;
        }

        return ['selector' => $m[1], 'verifier' => $m[2]];
    }

    /**
     * Prüft, ob der übergebene Verifier zum gespeicherten Argon2id-Hash passt.
     */
    public function verify(string $verifier, string $hash): bool
    {
        return password_verify($verifier, $hash);
    }
}
