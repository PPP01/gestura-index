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
use Doctrine\DBAL\LockMode;
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
            // Dieses Sicherheitsereignis muss unabhängig persistieren – daher
            // bewusst außerhalb der Registrierungs-Transaktion, damit es nicht
            // mit einem späteren Rollback verschwindet.
            $audit->log($invite->adminUser, 'user.register_denied', 'admin_user', (string) $invite->adminUser->id, ['reason' => 'disabled']);
            throw new ApiProblem(409, 'Account is disabled');
        }

        $attestationJson = json_encode($body['attestation'] ?? $body, JSON_THROW_ON_ERROR);

        // Registrierung atomar und unter Zeilensperre von Invite UND Nutzer:
        // ein zweiter paralleler Request mit demselben Token blockiert auf dem
        // FOR-UPDATE und sieht danach das gesetzte usedAt (kein Doppel-Einlösen);
        // eine parallele Deaktivierung wird durch die Nutzer-Sperre + Recheck
        // erkannt, statt sie mit dem vorgeladenen Objekt zu überschreiben.
        // Konflikte als Sentinel zurückgeben (nicht werfen) → EM bleibt offen.
        $conflict = $em->wrapInTransaction(function () use ($em, $invites, $invite, $ceremony, $attestationJson, $audit): ?string {
            $em->lock($invite, LockMode::PESSIMISTIC_WRITE);
            $em->refresh($invite);
            $user = $invite->adminUser;
            $em->lock($user, LockMode::PESSIMISTIC_WRITE);
            $em->refresh($user);

            if (null !== $invite->usedAt) {
                return 'used';
            }
            // Parallel deaktiviert: nicht wieder auf Active schreiben.
            if (AdminUserStatus::Disabled === $user->status) {
                return 'disabled';
            }

            $ceremony->verifyRegistration($user, $attestationJson, 'Erster Passkey');

            // Invited → Active (Erst-Registrierung); Active bleibt Active
            // (Recovery: verlorener Passkey, neuer wird per Invite hinzugefügt).
            $user->status = AdminUserStatus::Active;
            $invite->usedAt = new \DateTimeImmutable();

            // Geschwister-Invites desselben Nutzers invalidieren, damit ein
            // liegen gebliebenes zweites Token nicht später replayed werden kann.
            foreach ($invites->findUnusedForUser($user) as $sibling) {
                if ($sibling->id !== $invite->id) {
                    $sibling->usedAt = new \DateTimeImmutable();
                }
            }

            $audit->log($user, 'user.register', 'admin_user', (string) $user->id);

            return null;
        });

        if ('used' === $conflict) {
            throw new ApiProblem(409, 'Invite already used');
        }
        if ('disabled' === $conflict) {
            // Sicherheitsereignis unabhängig persistieren (nach dem leeren Commit).
            $audit->log($invite->adminUser, 'user.register_denied', 'admin_user', (string) $invite->adminUser->id, ['reason' => 'disabled']);
            throw new ApiProblem(409, 'Account is disabled');
        }

        return new JsonResponse(['status' => 'active'], 201);
    }
}
