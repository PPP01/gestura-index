<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Exception\ApiProblem;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

final class ProblemJsonSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::EXCEPTION => 'onKernelException'];
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        if (!str_starts_with($event->getRequest()->getPathInfo(), '/api/')) {
            return;
        }

        $throwable = $event->getThrowable();
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
