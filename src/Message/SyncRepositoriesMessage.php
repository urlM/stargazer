<?php

declare(strict_types=1);

namespace App\Message;

final readonly class SyncRepositoriesMessage
{
    /**
     * @param list<array{min: int, max: int|null, page: int}> $pendingShards
     */
    public function __construct(
        public string $language,
        public int $maxRepositories,
        public string $correlationId,
        public string $queuedAt,
        public string $triggeredBy = 'manual',
        public string $starRangeKey = 'all',
        public ?int $remainingRepositories = null,
        public int $syncedCount = 0,
        public array $pendingShards = [],
    ) {
    }
}
