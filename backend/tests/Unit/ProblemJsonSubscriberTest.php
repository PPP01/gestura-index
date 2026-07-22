<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\EventSubscriber\ProblemJsonSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Deckt zwei Zweige ab, die im funktionalen KernelBrowser-Testlauf nicht
 * erreichbar sind (vgl. AdminCrossCuttingSubscriberTest):
 * - getSubscribedEvents() wird vom RegisterListenersPass beim
 *   Container-Compile aufgerufen, nicht zur Request-Laufzeit — mit dem
 *   bereits gewarmten Test-Container zählt der Aufruf nicht als
 *   Coverage-Treffer. Direkter statischer Aufruf schließt die Lücke.
 * - der frühe Rückkehr-Zweig für Pfade außerhalb von /api/: die Anwendung
 *   ist eine reine API (alle Routen liegen unter /api/), weshalb dieser
 *   Zweig über einen echten HTTP-Request im KernelBrowser nicht auslösbar
 *   ist.
 */
final class ProblemJsonSubscriberTest extends TestCase
{
    public function testSubscribesToKernelException(): void
    {
        self::assertSame(
            [KernelEvents::EXCEPTION => 'onKernelException'],
            ProblemJsonSubscriber::getSubscribedEvents(),
        );
    }

    public function testIgnoresExceptionsOutsideApiPaths(): void
    {
        $kernel = $this->createStub(HttpKernelInterface::class);
        $event = new ExceptionEvent(
            $kernel,
            Request::create('/robots.txt'),
            HttpKernelInterface::MAIN_REQUEST,
            new \RuntimeException('kaputt'),
        );

        (new ProblemJsonSubscriber())->onKernelException($event);

        // Kein problem+json — der Subscriber lässt Nicht-API-Pfade unangetastet.
        self::assertFalse($event->hasResponse());
    }
}
