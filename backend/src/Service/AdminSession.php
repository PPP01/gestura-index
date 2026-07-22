<?php
declare(strict_types=1);
namespace App\Service;

use App\Entity\AdminUser;
use Symfony\Component\HttpFoundation\RequestStack;

final class AdminSession
{
    private const KEY_USER = '_admin_user_id';
    private const KEY_EMAIL = '_admin_user_email';
    private const KEY_VERIFIED = '_admin_verified_at';
    private const KEY_CHALLENGE = '_admin_challenge_';

    public function __construct(private readonly RequestStack $requestStack) {}

    public function login(AdminUser $u): void
    {
        $s = $this->requestStack->getSession();
        // Session-ID bei jedem Login rotieren (Fixation-Schutz): eine vor
        // dem Login bereits bestehende (z. B. vorab bekannte) Session-ID
        // darf nach dem Login nicht weiterverwendbar sein. `migrate(true)`
        // behält die Attribute, ersetzt aber die ID.
        $s->migrate(true);
        $s->set(self::KEY_USER, $u->id);
        $s->set(self::KEY_EMAIL, $u->email);
        $s->set(self::KEY_VERIFIED, time());
    }

    public function currentUserId(): ?int
    {
        return $this->requestStack->getSession()->get(self::KEY_USER);
    }

    public function currentUserEmail(): ?string
    {
        return $this->requestStack->getSession()->get(self::KEY_EMAIL);
    }

    public function markVerified(): void
    {
        $this->requestStack->getSession()->set(self::KEY_VERIFIED, time());
    }

    public function isFresh(int $maxAgeSeconds): bool
    {
        $ts = $this->requestStack->getSession()->get(self::KEY_VERIFIED);
        return is_int($ts) && (time() - $ts) <= $maxAgeSeconds;
    }

    public function logout(): void
    {
        $this->requestStack->getSession()->invalidate();
    }

    public function putChallenge(string $key, string $challengeB64): void
    {
        $this->requestStack->getSession()->set(self::KEY_CHALLENGE . $key, $challengeB64);
    }

    public function takeChallenge(string $key): ?string
    {
        $s = $this->requestStack->getSession();
        $v = $s->get(self::KEY_CHALLENGE . $key);
        $s->remove(self::KEY_CHALLENGE . $key);
        return is_string($v) ? $v : null;
    }
}
