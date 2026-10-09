<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\EventSubscriber\CorsSubscriber;
use App\EventSubscriber\SyncMaintenanceSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\EventListener\RouterListener;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Die Priorität des Wartungs-Subscribers ist Teil seines Vertrags: Sie muss
 * vor dem Router liegen und hinter dem CORS-Preflight. Kein Funktionstest
 * fängt eine Verschiebung ab – der Limiter sitzt im Controller, und OPTIONS
 * ist im Subscriber zusätzlich defensiv abgefangen –, aber hinter dem Router
 * liefe ein falscher Methodenaufruf auf /api/v1/sync/ping in der Wartung auf
 * ein 405 statt eines 503.
 */
final class SyncMaintenanceSubscriberTest extends TestCase
{
    public function testPriorityLiesBetweenRouterAndCors(): void
    {
        $priority = $this->requestPriority(SyncMaintenanceSubscriber::getSubscribedEvents());

        self::assertGreaterThan(
            $this->routerPriority(),
            $priority,
            'Der Wartungs-Subscriber muss vor dem Router laufen.',
        );
        self::assertLessThan(
            $this->requestPriority(CorsSubscriber::getSubscribedEvents()),
            $priority,
            'Der CORS-Preflight muss vor dem Wartungs-Subscriber beantwortet werden.',
        );
    }

    /**
     * @param array<string, mixed> $events
     */
    private function requestPriority(array $events): int
    {
        self::assertArrayHasKey(KernelEvents::REQUEST, $events);
        $listener = $events[KernelEvents::REQUEST];
        self::assertIsArray($listener);

        return (int) $listener[1];
    }

    /** RouterListener meldet seine Listener als Liste von [Methode, Priorität]. */
    private function routerPriority(): int
    {
        $listeners = RouterListener::getSubscribedEvents()[KernelEvents::REQUEST];
        self::assertIsArray($listeners);

        $priorities = [];
        foreach ($listeners as $listener) {
            if (($listener[0] ?? null) === 'onKernelRequest') {
                $priorities[] = (int) $listener[1];
            }
        }
        self::assertCount(1, $priorities, 'RouterListener::onKernelRequest nicht eindeutig gefunden.');

        return $priorities[0];
    }
}
