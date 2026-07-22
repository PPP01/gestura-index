<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Service\AdminSession;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * Isolierter Nachweis für AdminSession-Zweige, die im funktionalen Testlauf
 * nicht (leicht) erreichbar sind: der Zustand vor jedem Login, die
 * One-Shot-Semantik der WebAuthn-Challenges (BundleWebAuthnCeremony nutzt
 * sie, die Test-Umgebung aber FakeWebAuthnCeremony — siehe dort) und Logout.
 */
final class AdminSessionTest extends TestCase
{
    private function newSession(): AdminSession
    {
        $session = new Session(new MockArraySessionStorage());
        $request = new Request();
        $request->setSession($session);
        $requestStack = new RequestStack();
        $requestStack->push($request);

        return new AdminSession($requestStack);
    }

    public function testCurrentUserIdAndEmailAreNullWithoutLogin(): void
    {
        $session = $this->newSession();
        self::assertNull($session->currentUserId());
        self::assertNull($session->currentUserEmail());
    }

    public function testChallengeIsOneShot(): void
    {
        $session = $this->newSession();
        $session->putChallenge('reg', 'challenge-value');

        self::assertSame('challenge-value', $session->takeChallenge('reg'));
        // Zweites Lesen darf die Challenge nicht erneut liefern (Replay-Schutz).
        self::assertNull($session->takeChallenge('reg'));
    }

    public function testChallengesAreScopedByKey(): void
    {
        $session = $this->newSession();
        $session->putChallenge('reg', 'reg-challenge');
        $session->putChallenge('assert', 'assert-challenge');

        self::assertSame('reg-challenge', $session->takeChallenge('reg'));
        self::assertSame('assert-challenge', $session->takeChallenge('assert'));
    }

    public function testLogoutInvalidatesSession(): void
    {
        $session = $this->newSession();
        $session->putChallenge('reg', 'challenge-value');

        $session->logout();

        self::assertNull($session->takeChallenge('reg'));
        self::assertNull($session->currentUserId());
    }
}
