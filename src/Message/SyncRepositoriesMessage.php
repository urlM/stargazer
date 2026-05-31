<?php

namespace App\Message;

final readonly class SyncRepositoriesMessage
{
    public function __construct(
        public string $language,
        public int $limit,
        public string $correlationId,
        public string $queuedAt,
    ) {
    }
}
