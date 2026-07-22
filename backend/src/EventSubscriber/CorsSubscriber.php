<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * CORS-Differenzierung nach Pfad: `/api/admin` ist eine session-/cookie-
 * basierte, credentialed API mit fixem Origin (Svelte-Admin-SPA unter
 * https://gestura.eu). Alle übrigen `/api/`-Pfade bleiben die öffentliche,
 * cookielose "*"-API mit Bearer-Tokens — Extension-Service-Worker und
 * Svelte-Website greifen beide frei zu (Spec, Abschnitt Querschnitt).
 */
final class CorsSubscriber implements EventSubscriberInterface
{
    /**
     * Registriert den Subscriber auf REQUEST (Priorität 256, vor dem Router)
     * für den OPTIONS-Preflight sowie auf RESPONSE zum Setzen der CORS-Header.
     */
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 256],
            KernelEvents::RESPONSE => 'onKernelResponse',
        ];
    }

    /**
     * Beantwortet OPTIONS-Preflight-Anfragen unter /api/ sofort mit HTTP 204,
     * damit der Preflight nicht in die Symfony-Routing-Kette gelangt.
     */
    public function onKernelRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if ($request->getMethod() === 'OPTIONS' && str_starts_with($request->getPathInfo(), '/api/')) {
            $event->setResponse(new Response('', 204, $this->corsHeaders($request)));
        }
    }

    /**
     * Grenzsichere Erkennung des Admin-Pfad-Präfixes: exakt `/api/admin` oder
     * `/api/admin/...` – vermeidet Fehltreffer wie `/api/administrators`.
     */
    private function isAdminPath(string $path): bool
    {
        return $path === '/api/admin' || str_starts_with($path, '/api/admin/');
    }

    /**
     * Ergänzt jede Antwort auf /api/-Pfade um die CORS-Header, sodass Browser-
     * Clients (Extension-Service-Worker, Svelte-Website, Admin-SPA) die
     * Ressource lesen dürfen.
     */
    public function onKernelResponse(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        if (str_starts_with($request->getPathInfo(), '/api/')) {
            $event->getResponse()->headers->add($this->corsHeaders($request));
        }
    }

    /**
     * Liefert das CORS-Antwort-Header-Set passend zum Request-Pfad: für
     * `/api/admin` credentialed mit fixem Origin (Session-Cookie), sonst die
     * öffentliche "*"-Variante ohne Credentials — unverändert zum bisherigen
     * Verhalten der öffentlichen API.
     *
     * @return array<string, string>
     */
    private function corsHeaders(Request $request): array
    {
        if ($this->isAdminPath($request->getPathInfo())) {
            return [
                'Access-Control-Allow-Origin' => 'https://gestura.eu',
                'Access-Control-Allow-Credentials' => 'true',
                'Access-Control-Allow-Methods' => 'GET, POST, PATCH, DELETE, OPTIONS',
                'Access-Control-Allow-Headers' => 'Content-Type, X-Requested-With',
                'Access-Control-Max-Age' => '86400',
                'Vary' => 'Origin',
            ];
        }

        return [
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Methods' => 'GET, POST, PUT, DELETE, OPTIONS',
            'Access-Control-Allow-Headers' => 'Authorization, Content-Type',
            'Access-Control-Max-Age' => '86400',
        ];
    }
}
