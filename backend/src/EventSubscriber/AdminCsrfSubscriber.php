<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Exception\ApiProblem;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * CSRF-Schutz für die session-/cookie-basierte Admin-API: zustandsändernde
 * Requests unter `/api/admin` müssen den Header `X-Requested-With:
 * XMLHttpRequest` mitschicken. Cross-Site-HTML-Formulare können diesen
 * Header nicht setzen, wodurch klassisches CSRF gegen die Admin-Session
 * blockiert wird (zusätzlich zu SameSite=Strict des Session-Cookies).
 */
final class AdminCsrfSubscriber implements EventSubscriberInterface
{
    /**
     * Priorität 200: nach dem OPTIONS-Preflight von CorsSubscriber (256,
     * stoppt die Propagation für Preflights ohnehin), aber vor dem Router.
     */
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onKernelRequest', 200]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if (!$this->isAdminPath($request->getPathInfo())) {
            return;
        }

        if (\in_array($request->getMethod(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return;
        }

        if ('XMLHttpRequest' !== $request->headers->get('X-Requested-With')) {
            throw new ApiProblem(403, 'Missing X-Requested-With header');
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
}
