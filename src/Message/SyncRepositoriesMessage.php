<?php

declare(strict_types=1);

namespace App\Message;

final class SyncRepositoriesMessage
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

    /**
     * @return array{
     *     language: string,
     *     maxRepositories: int,
     *     correlationId: string,
     *     queuedAt: string,
     *     triggeredBy: string,
     *     starRangeKey: string,
     *     remainingRepositories: int|null,
     *     syncedCount: int,
     *     pendingShards: list<array{min: int, max: int|null, page: int}>
     * }
     */
    public function __serialize(): array
    {
        return [
            'language' => $this->language,
            'maxRepositories' => $this->maxRepositories,
            'correlationId' => $this->correlationId,
            'queuedAt' => $this->queuedAt,
            'triggeredBy' => $this->triggeredBy,
            'starRangeKey' => $this->starRangeKey,
            'remainingRepositories' => $this->remainingRepositories,
            'syncedCount' => $this->syncedCount,
            'pendingShards' => $this->pendingShards,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public function __unserialize(array $data): void
    {
        $this->language = (string) ($data['language'] ?? 'php');
        $this->maxRepositories = (int) ($data['maxRepositories'] ?? $data['limit'] ?? 100);
        $this->correlationId = (string) ($data['correlationId'] ?? '');
        $this->queuedAt = (string) ($data['queuedAt'] ?? '');
        $this->triggeredBy = (string) ($data['triggeredBy'] ?? 'manual');
        $this->starRangeKey = (string) ($data['starRangeKey'] ?? 'all');
        $this->remainingRepositories = isset($data['remainingRepositories']) ? (int) $data['remainingRepositories'] : null;
        $this->syncedCount = (int) ($data['syncedCount'] ?? 0);
        $this->pendingShards = is_array($data['pendingShards'] ?? null) ? $data['pendingShards'] : [];
    }
}
