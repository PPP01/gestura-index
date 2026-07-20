<?php

declare(strict_types=1);

namespace App\Service;

final class EditTokenService
{
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

    /** @return array{selector: string, verifier: string}|null */
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

    public function verify(string $verifier, string $hash): bool
    {
        return password_verify($verifier, $hash);
    }
}
