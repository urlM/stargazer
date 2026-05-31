<?php

declare(strict_types=1);

namespace App\Tests\Unit\Logging;

use App\Logging\LogContextProcessor;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class LogContextProcessorTest extends TestCase
{
    public function testAddsRequestCorrelationIdAndSyncContext(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push(new Request(
            query: ['language' => 'php', 'limit' => '100'],
            server: ['HTTP_X_CORRELATION_ID' => 'request-correlation-id'],
        ));

        $processor = new LogContextProcessor($requestStack);

        $record = $processor(new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'app',
            level: Level::Info,
            message: 'test',
            context: [
                'duration_ms' => 42,
                'retry_count' => 1,
                'rate_limit_remaining' => 4999,
            ],
        ));

        self::assertSame('request-correlation-id', $record->extra['correlation_id']);
        self::assertSame('php', $record->extra['language']);
        self::assertSame('100', $record->extra['limit']);
        self::assertSame(42, $record->extra['duration_ms']);
        self::assertSame(1, $record->extra['retry_count']);
        self::assertSame(4999, $record->extra['rate_limit_remaining']);
    }

    public function testContextCorrelationIdOverridesRequestHeader(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push(new Request(server: ['HTTP_X_CORRELATION_ID' => 'request-correlation-id']));

        $processor = new LogContextProcessor($requestStack);

        $record = $processor(new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'app',
            level: Level::Info,
            message: 'test',
            context: ['correlation_id' => 'context-correlation-id'],
        ));

        self::assertSame('context-correlation-id', $record->extra['correlation_id']);
    }
}
