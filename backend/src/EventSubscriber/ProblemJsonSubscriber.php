<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Exception\ApiProblem;
use App\Exception\SyncProblem;
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
 * EINE Ausnahme: SyncProblem. Die Locator-Sync-Endpunkte antworten in der
 * Form, die der Extension-Vertrag wörtlich vorschreibt – { "error": "<code>" }
 * als application/json. Ein zweiter Antwortstil ist hier kein Wildwuchs,
 * sondern Vertragserfüllung.
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

        // Die Locator-Sync-Endpunkte antworten in der Vertragsform. Der
        // Client bildet die Fehlercodes allein über den HTTP-Status ab und
        // liest genau einen Body – den des 412, und daraus nur updatedAt.
        if ($throwable instanceof SyncProblem) {
            $event->setResponse(new JsonResponse(
                ['error' => $throwable->errorCode] + $throwable->extra,
                $throwable->getStatusCode(),
                $throwable->getHeaders(),
            ));

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
