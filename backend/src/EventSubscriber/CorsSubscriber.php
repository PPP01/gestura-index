<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * CORS "*" für die gesamte /api/: öffentliche, cookielose API mit
 * Bearer-Tokens — Extension-Service-Worker und Svelte-Website greifen
 * beide frei zu (Spec, Abschnitt Querschnitt).
 */
final class CorsSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 256],
            KernelEvents::RESPONSE => 'onKernelResponse',
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if ($request->getMethod() === 'OPTIONS' && str_starts_with($request->getPathInfo(), '/api/')) {
            $event->setResponse(new Response('', 204, $this->corsHeaders()));
        }
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (str_starts_with($event->getRequest()->getPathInfo(), '/api/')) {
            $event->getResponse()->headers->add($this->corsHeaders());
        }
    }

    /** @return array<string, string> */
    private function corsHeaders(): array
    {
        return [
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Methods' => 'GET, POST, PUT, DELETE, OPTIONS',
            'Access-Control-Allow-Headers' => 'Authorization, Content-Type',
            'Access-Control-Max-Age' => '86400',
        ];
    }
}
