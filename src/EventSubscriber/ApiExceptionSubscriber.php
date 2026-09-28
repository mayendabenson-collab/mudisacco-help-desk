<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

class ApiExceptionSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::EXCEPTION => 'onKernelException'];
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        $request = $event->getRequest();

        if (!str_starts_with($request->getPathInfo(), '/api')) {
            return;
        }

        $exception = $event->getThrowable();

        if ($request->getPathInfo() === '/api/health' || $request->getPathInfo() === '/health') {
            $this->logger->warning('Health check degraded.', [
                'exception' => $exception->getMessage(),
            ]);
            $event->setResponse(new JsonResponse([
                'service' => 'mudi-sacco-support',
                'status' => 'degraded',
                'checks' => [
                    'database' => 'unavailable',
                    'database_error' => $exception->getMessage(),
                ],
                'timestamp' => (new \DateTimeImmutable())->format(DATE_ATOM),
            ], 200));
            return;
        }

        $statusCode = $exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : 500;

        if ($statusCode >= 500) {
            $this->logger->error('Unhandled API exception.', [
                'exception' => $exception,
                'path' => $request->getPathInfo(),
            ]);
        }

        $event->setResponse(new JsonResponse([
            'error' => [
                'status' => $statusCode,
                'message' => $statusCode >= 500 ? 'An unexpected server error occurred.' : $exception->getMessage(),
            ],
        ], $statusCode));
    }
}
