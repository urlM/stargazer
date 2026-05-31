<?php

declare(strict_types=1);

namespace App\Tests\Unit\Message;

use App\Message\SyncRepositoriesMessage;
use PHPUnit\Framework\TestCase;

final class SyncRepositoriesMessageTest extends TestCase
{
    public function testSerializeRoundTripPreservesCanonicalFields(): void
    {
        $message = new SyncRepositoriesMessage(
            'php',
            500,
            'corr-123',
            '2026-05-31T12:00:00+00:00',
            'manual',
            '5000_9999',
            250,
            100,
            [['min' => 5000, 'max' => 9999, 'page' => 2]],
        );

        $restored = unserialize(serialize($message));

        self::assertInstanceOf(SyncRepositoriesMessage::class, $restored);
        self::assertSame(500, $restored->maxRepositories);
        self::assertSame('5000_9999', $restored->starRangeKey);
        self::assertSame(250, $restored->remainingRepositories);
        self::assertSame(100, $restored->syncedCount);
        self::assertSame([['min' => 5000, 'max' => 9999, 'page' => 2]], $restored->pendingShards);
    }

    public function testUnserializeMapsLegacyLimitFieldToMaxRepositories(): void
    {
        $reflection = new \ReflectionClass(SyncRepositoriesMessage::class);
        $message = $reflection->newInstanceWithoutConstructor();

        $message->__unserialize([
            'language' => 'php',
            'limit' => 1000,
            'correlationId' => 'corr-legacy',
            'queuedAt' => '2026-05-31T12:00:00+00:00',
        ]);

        self::assertSame('php', $message->language);
        self::assertSame(1000, $message->maxRepositories);
        self::assertSame('corr-legacy', $message->correlationId);
        self::assertSame('manual', $message->triggeredBy);
        self::assertSame('all', $message->starRangeKey);
        self::assertNull($message->remainingRepositories);
        self::assertSame(0, $message->syncedCount);
        self::assertSame([], $message->pendingShards);
    }
}
