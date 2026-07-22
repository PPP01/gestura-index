<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Service\AdminSession;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * Isolierter Nachweis für AdminSession::isFresh(): eine veraltete
 * `_admin_verified_at`-Zeitmarke darf den Step-up-Freshness-Check nicht
 * mehr bestehen, eine aktuelle schon (Grundlage für StepUpGuard).
 */
final class AdminSessionFreshnessTest extends TestCase
{
    private function sessionWithVerifiedAt(?int $timestamp): AdminSession
    {
        $session = new Session(new MockArraySessionStorage());
        if (null !== $timestamp) {
            $session->set('_admin_verified_at', $timestamp);
        }
        // RequestStack::getSession() liest die Session vom aktuellen Request;
        // dafür braucht der Request eine gesetzte Session.
        $requestStack = new RequestStack();
        $request = new \Symfony\Component\HttpFoundation\Request();
        $request->setSession($session);
        $requestStack->push($request);

        return new AdminSession($requestStack);
    }

    public function testStaleVerificationIsNotFresh(): void
    {
        $adminSession = $this->sessionWithVerifiedAt(time() - 400);
        self::assertFalse($adminSession->isFresh(300));
    }

    public function testRecentVerificationIsFresh(): void
    {
        $adminSession = $this->sessionWithVerifiedAt(time());
        self::assertTrue($adminSession->isFresh(300));
    }
}
