<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Enum\AdminUserStatus;
use App\Exception\ApiProblem;
use App\Repository\AdminInviteRepository;
use App\Service\AuditLogger;
use App\Service\InviteTokenService;
use App\Service\RateLimitGuard;
use App\Service\WebAuthn\WebAuthnCeremony;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Schließt die Registrierung ab: prüft das Invite-Token (Selector + Argon2id-
 * Verifier, Ablauf, noch nicht verbraucht), verifiziert den ersten Passkey
 * über die WebAuthn-Zeremonie und aktiviert den Account.
 */
final class RegisterController
{
    #[Route('/api/admin/register', methods: ['POST'])]
    public function __invoke(
        Request $request,
        InviteTokenService $tokens,
        AdminInviteRepository $invites,
        WebAuthnCeremony $ceremony,
        EntityManagerInterface $em,
        AuditLogger $audit,
        RateLimitGuard $guard,
        RateLimiterFactoryInterface $adminRegisterLimiter,
    ): JsonResponse {
        $guard->consume($adminRegisterLimiter, $request->getClientIp() ?? 'unknown');

        try {
            $body = json_decode($request->getContent(), true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ApiProblem(400, 'Invalid JSON body');
        }

        $parsed = $tokens->parse((string) ($body['token'] ?? '')) ?? throw new ApiProblem(400, 'Invalid token');
        $invite = $invites->findOneBySelector($parsed['selector']) ?? throw new ApiProblem(404, 'Invite not found');

        if (null !== $invite->usedAt || $invite->expiresAt < new \DateTimeImmutable() || !$tokens->verify($parsed['verifier'], $invite->tokenHash)) {
            throw new ApiProblem(400, 'Invite is invalid or expired');
        }

        if (AdminUserStatus::Disabled === $invite->adminUser->status) {
            $audit->log($invite->adminUser, 'user.register_denied', 'admin_user', (string) $invite->adminUser->id, ['reason' => 'disabled']);
            throw new ApiProblem(409, 'Account is disabled');
        }

        $attestationJson = json_encode($body['attestation'] ?? $body, JSON_THROW_ON_ERROR);
        $ceremony->verifyRegistration($invite->adminUser, $attestationJson, 'Erster Passkey');

        // Invited → Active (Erst-Registrierung); Active bleibt Active
        // (Recovery: verlorener Passkey, neuer wird per Invite hinzugefügt).
        $invite->adminUser->status = AdminUserStatus::Active;
        $invite->usedAt = new \DateTimeImmutable();

        // Geschwister-Invites desselben Nutzers invalidieren, damit ein
        // liegen gebliebenes zweites Token nicht später replayed werden kann.
        foreach ($invites->findUnusedForUser($invite->adminUser) as $sibling) {
            if ($sibling->id !== $invite->id) {
                $sibling->usedAt = new \DateTimeImmutable();
            }
        }

        $em->flush();

        $audit->log($invite->adminUser, 'user.register', 'admin_user', (string) $invite->adminUser->id);

        return new JsonResponse(['status' => 'active'], 201);
    }
}
