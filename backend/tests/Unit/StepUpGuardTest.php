<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Exception\ApiProblem;
use App\Security\StepUpGuard;
use App\Service\AdminSession;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * Isolierter Nachweis für StepUpGuard::assertFresh(): eine veraltete
 * `_admin_verified_at`-Zeitmarke muss als 403 »Step-up required« mit
 * `stepUpRequired: true` gemeldet werden. Funktional ist dieser Zweig kaum
 * reproduzierbar, weil loginWithCredentials() die Session immer frisch
 * verifiziert zurücklässt (siehe AdminTestCase).
 */
final class StepUpGuardTest extends TestCase
{
    public function testAssertFreshRejectsStaleSession(): void
    {
        $session = new Session(new MockArraySessionStorage());
        $session->set('_admin_verified_at', time() - 400);
        $request = new Request();
        $request->setSession($session);
        $requestStack = new RequestStack();
        $requestStack->push($request);

        $guard = new StepUpGuard(new AdminSession($requestStack));

        try {
            $guard->assertFresh();
            self::fail('Erwartete ApiProblem-Exception blieb aus');
        } catch (ApiProblem $e) {
            self::assertSame(403, $e->getStatusCode());
            self::assertSame('Step-up required', $e->getMessage());
            self::assertTrue($e->extra['stepUpRequired']);
        }
    }
}
