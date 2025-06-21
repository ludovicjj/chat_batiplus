<?php

namespace App\EventListener;

use App\Exception\ValidatorException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;

class ExceptionSubscriber implements EventSubscriberInterface
{

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::EXCEPTION => ['onKernelException', 2]
        ];
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        if ($event->getThrowable() instanceof ValidatorException) {
            $this->sendJsonResponseException($event);
        }
    }

    private function sendJsonResponseException(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();
        $statusCode = $exception->getCode() ?: 400;

        $data = [
            'message'   => $exception->getMessage(),
            'code'      => $statusCode
        ];

        if ($exception instanceof ValidatorException) {
            $statusCode = Response::HTTP_UNPROCESSABLE_ENTITY;
            $data['errors'] = $exception->getErrors();
        }

        $event->setResponse(new JsonResponse($data, $statusCode));
    }
}