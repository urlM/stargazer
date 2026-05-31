<?php

declare(strict_types=1);

namespace App\Logging;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class LogContextProcessor implements ProcessorInterface
{
    private const REQUEST_ATTRIBUTE = '_stargazer_correlation_id';

    private ?string $correlationId = null;

    public function __construct(
        private readonly RequestStack $requestStack,
    ) {
    }

    public function __invoke(LogRecord $record): LogRecord
    {
        $request = $this->requestStack->getCurrentRequest();
        $context = $record->context;

        $record->extra = array_replace([
            'correlation_id' => $context['correlation_id'] ?? $this->resolveCorrelationId(),
            'language' => $context['language'] ?? $request?->query->get('language'),
            'limit' => $context['limit'] ?? $request?->query->get('limit'),
            'duration_ms' => $context['duration_ms'] ?? null,
            'retry_count' => $context['retry_count'] ?? null,
            'rate_limit_remaining' => $context['rate_limit_remaining'] ?? null,
        ], $record->extra);

        return $record;
    }

    private function resolveCorrelationId(): string
    {
        $request = $this->requestStack->getCurrentRequest();

        if ($request instanceof Request) {
            $correlationId = $request->attributes->get(self::REQUEST_ATTRIBUTE);

            if (is_string($correlationId) && $correlationId !== '') {
                return $correlationId;
            }

            $correlationId = $request->headers->get('X-Correlation-ID')
                ?? $request->headers->get('X-Request-ID')
                ?? bin2hex(random_bytes(16));

            $request->attributes->set(self::REQUEST_ATTRIBUTE, $correlationId);

            return $correlationId;
        }

        if ($this->correlationId !== null) {
            return $this->correlationId;
        }

        $this->correlationId = bin2hex(random_bytes(16));

        return $this->correlationId;
    }
}
