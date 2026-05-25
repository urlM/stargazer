<?php

declare(strict_types=1);

namespace App\Dto;

use DateTimeImmutable;

/**
 * Encapsulates repository data from the GitHub Search API.
 */
final readonly class GitHubRepositoryDTO
{
    public function __construct(
        public string $id,
        public string $name,
        public string $url,
        public ?string $description,
        public int $stars,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $pushedAt,
    ) {
    }
}
