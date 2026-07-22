<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\EventSubscriber\AdminCsrfSubscriber;
use App\EventSubscriber\CorsSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Deckt zwei Zweige ab, die im funktionalen KernelBrowser-Testlauf nicht
 * erreichbar sind:
 * - getSubscribedEvents() wird vom RegisterListenersPass beim
 *   Container-Compile aufgerufen, nicht zur Request-Laufzeit — mit
 *   XDEBUG_MODE=coverage bereits gewarmten Test-Container zählt der Aufruf
 *   nicht als Coverage-Treffer. Direkter statischer Aufruf schließt die Lücke.
 * - isMainRequest() === false (Sub-Request, z. B. ESI/forward()) kommt im
 *   KernelBrowser-Testlauf nie vor; hier direkt mit einem echten
 *   RequestEvent im Sub-Request-Modus konstruiert.
 */
final class AdminCrossCuttingSubscriberTest extends TestCase
{
    public function testAdminCsrfSubscriberSubscribesToKernelRequest(): void
    {
        self::assertSame(
            [KernelEvents::REQUEST => ['onKernelRequest', 200]],
            AdminCsrfSubscriber::getSubscribedEvents(),
        );
    }

    public function testAdminCsrfSubscriberIgnoresSubRequests(): void
    {
        $kernel = $this->createStub(HttpKernelInterface::class);
        $event = new RequestEvent($kernel, new Request(), HttpKernelInterface::SUB_REQUEST);

        // Muss ohne Exception durchlaufen und keine Response setzen — der
        // CSRF-Header wird für Sub-Requests nicht verlangt.
        (new AdminCsrfSubscriber())->onKernelRequest($event);

        self::assertFalse($event->hasResponse());
    }

    public function testCorsSubscriberSubscribesToRequestAndResponse(): void
    {
        $events = CorsSubscriber::getSubscribedEvents();

        self::assertSame(['onKernelRequest', 256], $events[KernelEvents::REQUEST]);
        self::assertSame('onKernelResponse', $events[KernelEvents::RESPONSE]);
    }
}
