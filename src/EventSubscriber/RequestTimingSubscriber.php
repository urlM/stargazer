<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class RequestTimingSubscriber implements EventSubscriberInterface
{
    private const START_ATTRIBUTE = '_stargazer_request_started_at';

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly bool $stargazerTimingEnabled,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => 'onKernelRequest',
            KernelEvents::RESPONSE => 'onKernelResponse',
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$this->stargazerTimingEnabled || !$event->isMainRequest()) {
            return;
        }

        $event->getRequest()->attributes->set(self::START_ATTRIBUTE, microtime(true));
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$this->stargazerTimingEnabled || !$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $startedAt = $request->attributes->get(self::START_ATTRIBUTE);
        if (!is_float($startedAt)) {
            return;
        }

        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
        $this->logger->info('Request timing probe.', [
            'path' => $request->getPathInfo(),
            'route' => $request->attributes->get('_route'),
            'method' => $request->getMethod(),
            'status_code' => $event->getResponse()->getStatusCode(),
            'duration_ms' => $durationMs,
        ]);
    }
}

