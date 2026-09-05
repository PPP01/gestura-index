<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Exception\ApiProblem;
use App\Exception\RendersOwnApiResponse;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Wandelt jede nicht behandelte Exception auf /api/-Pfaden in eine
 * RFC 7807 »application/problem+json«-Antwort um und unterdrückt damit
 * HTML-Fehlerseiten gegenüber API-Clients.
 *
 * Ausgenommen sind Exceptions, die ihre Antwort selbst kennen
 * (RendersOwnApiResponse) – etwa die Locator-Sync-Endpunkte, denen der
 * Extension-Vertrag eine eigene Form vorschreibt. Der Subscriber fragt nach
 * der Form, statt einzelne Feature-Exceptions namentlich zu kennen.
 */
final class ProblemJsonSubscriber implements EventSubscriberInterface
{
    /**
     * Registriert den Subscriber auf KernelEvents::EXCEPTION.
     */
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::EXCEPTION => 'onKernelException'];
    }

    /**
     * Fängt Exceptions auf /api/-Pfaden ab, bestimmt den HTTP-Statuscode
     * (aus HttpExceptionInterface oder 500) und setzt eine problem+json-Antwort.
     * ApiProblem-Instanzen können über das $extra-Array zusätzliche Felder
     * (z. B. eine errors-Liste) einmischen.
     */
    public function onKernelException(ExceptionEvent $event): void
    {
        if (!str_starts_with($event->getRequest()->getPathInfo(), '/api/')) {
            return;
        }

        $throwable = $event->getThrowable();

        // Wer seine Antwort selbst kennt, bekommt sie unverändert.
        if ($throwable instanceof RendersOwnApiResponse) {
            $event->setResponse($throwable->toApiResponse());

            return;
        }

        $status = $throwable instanceof HttpExceptionInterface ? $throwable->getStatusCode() : 500;
        $headers = $throwable instanceof HttpExceptionInterface ? $throwable->getHeaders() : [];

        $data = [
            'type' => 'about:blank',
            'title' => $status === 500 ? 'Internal Server Error' : $throwable->getMessage(),
            'status' => $status,
        ];
        if ($throwable instanceof ApiProblem) {
            $data += $throwable->extra;
        }

        $response = new JsonResponse($data, $status, $headers);
        $response->headers->set('Content-Type', 'application/problem+json');
        $event->setResponse($response);
    }
}
