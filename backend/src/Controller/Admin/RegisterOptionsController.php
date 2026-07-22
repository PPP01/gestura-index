<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Enum\AdminUserStatus;
use App\Exception\ApiProblem;
use App\Repository\AdminInviteRepository;
use App\Service\InviteTokenService;
use App\Service\RateLimitGuard;
use App\Service\WebAuthn\WebAuthnCeremony;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Erzeugt die WebAuthn-Creation-Options für die Registrierung anhand eines
 * gültigen Invite-Tokens. Öffentlich (kein eingeloggter Admin nötig) – die
 * Einladung selbst ist der Legitimationsnachweis.
 */
final class RegisterOptionsController
{
    #[Route('/api/admin/register/options', methods: ['POST'])]
    public function __invoke(
        Request $request,
        InviteTokenService $tokens,
        AdminInviteRepository $invites,
        WebAuthnCeremony $ceremony,
        RateLimitGuard $guard,
        RateLimiterFactoryInterface $adminRegisterLimiter,
    ): JsonResponse {
        // Parität zu RegisterController: die Argon2id-Verifikation ist ein
        // teurer CPU-Sink und braucht dieselbe Drosselung wie der eigentliche
        // Register-Endpunkt, bevor das Token überhaupt geparst wird.
        $guard->consume($adminRegisterLimiter, $request->getClientIp() ?? 'unknown');

        try {
            $body = json_decode($request->getContent(), true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ApiProblem(400, 'Invalid JSON body');
        }

        $parsed = $tokens->parse((string) ($body['token'] ?? '')) ?? throw new ApiProblem(400, 'Invalid token');
        $invite = $invites->findOneBySelector($parsed['selector']) ?? throw new ApiProblem(404, 'Invite not found');

        if (null !== $invite->usedAt || $invite->expiresAt < new \DateTimeImmutable() || !$tokens->verify($parsed['verifier'], $invite->tokenHash)) {
            throw new ApiProblem(400, 'Invite is invalid or expired');
        }

        if (AdminUserStatus::Disabled === $invite->adminUser->status) {
            throw new ApiProblem(409, 'Account is disabled');
        }

        return JsonResponse::fromJsonString($ceremony->creationOptionsJson($invite->adminUser));
    }
}
