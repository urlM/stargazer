<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * @phpstan-type RepositoryListItem array{id: string, name: string, stars: int|string}
 */
final readonly class GitHubRepositorySearchPageResult
{
    /**
     * @param array<GitHubRepositoryDTO> $repositories
     */
    public function __construct(
        public array $repositories,
        public int $totalCount,
        public int $page,
        public int $perPage,
        public ?int $rateLimitRemaining = null,
    ) {
    }

    public function hasNextPage(): bool
    {
        return $this->page * $this->perPage < min($this->totalCount, 1000);
    }
}
