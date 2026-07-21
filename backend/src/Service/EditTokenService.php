<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Erzeugt und prüft anonyme Edit-Tokens für die besitzlose Verwaltung von Einträgen.
 * Das Klartext-Token wird dem Einreicher einmalig ausgehändigt und nie gespeichert;
 * persistiert werden nur Selector und Argon2id-Hash des Verifiers.
 */
final class EditTokenService
{
    /**
     * Erstellt ein neues Edit-Token und gibt Klartext-Token, Selector und
     * Argon2id-Hash des Verifiers als GeneratedToken-Objekt zurück.
     * Das Token-Format ist »gsti_<selector16hex>_<verifier43base64url>«.
     */
    public function generate(): GeneratedToken
    {
        $selector = bin2hex(random_bytes(8));
        $verifier = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        return new GeneratedToken(
            token: sprintf('gsti_%s_%s', $selector, $verifier),
            selector: $selector,
            hash: password_hash($verifier, PASSWORD_ARGON2ID),
        );
    }

    /**
     * Zerlegt einen Bearer-Authorization-Header in Selector und Verifier.
     * Gibt null zurück, wenn der Header fehlt, kein Bearer-Prefix trägt oder
     * das Token-Format nicht dem erwarteten Muster entspricht.
     *
     * @return array{selector: string, verifier: string}|null
     */
    public function parseAuthorizationHeader(?string $header): ?array
    {
        if ($header === null || !str_starts_with($header, 'Bearer ')) {
            return null;
        }
        if (!preg_match('/^gsti_([0-9a-f]{16})_([A-Za-z0-9_-]{43})$/', trim(substr($header, 7)), $m)) {
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
