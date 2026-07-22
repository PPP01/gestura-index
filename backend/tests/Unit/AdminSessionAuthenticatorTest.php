<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Security\AdminSessionAuthenticator;
use App\Service\AdminSession;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Core\Exception\AuthenticationException;

/**
 * Deckt den defensiven Zweig in authenticate() ab: supports() liefert bereits
 * true, sobald eine Nutzer-ID in der Session steht. Fehlt dabei (inkonsistenter
 * Zustand — AdminSession::login() setzt normalerweise beides zusammen) die
 * E-Mail, muss authenticate() eine saubere AuthenticationException werfen
 * statt eines Fatal Errors — das führt über onAuthenticationFailure() zu 401.
 */
final class AdminSessionAuthenticatorTest extends TestCase
{
    public function testAuthenticateThrowsWhenEmailMissingButUserIdPresent(): void
    {
        $session = new Session(new MockArraySessionStorage());
        $session->set('_admin_user_id', 42); // ID ohne E-Mail — inkonsistent
        $request = new Request();
        $request->setSession($session);
        $requestStack = new RequestStack();
        $requestStack->push($request);

        $authenticator = new AdminSessionAuthenticator(new AdminSession($requestStack));

        self::assertTrue($authenticator->supports($request));

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('No admin session');
        $authenticator->authenticate($request);
    }
}
