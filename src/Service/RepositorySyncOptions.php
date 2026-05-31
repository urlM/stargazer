<?php

declare(strict_types=1);

namespace App\Service;

final class RepositorySyncOptions
{
    public const DEFAULT_STAR_RANGE = 'all';
    public const DEFAULT_MAX_REPOSITORIES = 100;
    private const SEARCH_RESULT_SPLIT_THRESHOLD = 1000;

    /**
     * @var array<string, array{label: string, min: int, max: int|null, seeds: list<array{min: int, max: int|null}>}>
     */
    private const STAR_RANGES = [
        'all' => [
            'label' => 'All stars',
            'min' => 0,
            'max' => null,
            'seeds' => [
                ['min' => 50000, 'max' => null],
                ['min' => 10000, 'max' => 49999],
                ['min' => 5000, 'max' => 9999],
                ['min' => 1000, 'max' => 4999],
                ['min' => 100, 'max' => 999],
                ['min' => 0, 'max' => 99],
            ],
        ],
        '50000_plus' => [
            'label' => '50,000+ stars',
            'min' => 50000,
            'max' => null,
            'seeds' => [
                ['min' => 50000, 'max' => null],
            ],
        ],
        '10000_49999' => [
            'label' => '10,000–49,999 stars',
            'min' => 10000,
            'max' => 49999,
            'seeds' => [
                ['min' => 10000, 'max' => 49999],
            ],
        ],
        '5000_9999' => [
            'label' => '5,000–9,999 stars',
            'min' => 5000,
            'max' => 9999,
            'seeds' => [
                ['min' => 5000, 'max' => 9999],
            ],
        ],
        '1000_4999' => [
            'label' => '1,000–4,999 stars',
            'min' => 1000,
            'max' => 4999,
            'seeds' => [
                ['min' => 1000, 'max' => 4999],
            ],
        ],
        '100_999' => [
            'label' => '100–999 stars',
            'min' => 100,
            'max' => 999,
            'seeds' => [
                ['min' => 100, 'max' => 999],
            ],
        ],
        '0_99' => [
            'label' => '0–99 stars',
            'min' => 0,
            'max' => 99,
            'seeds' => [
                ['min' => 0, 'max' => 99],
            ],
        ],
    ];

    /**
     * @var list<int>
     */
    private const MAX_REPOSITORY_OPTIONS = [100, 500, 1000, 2500, 5000];

    /**
     * @return array<string, string>
     */
    public function starRangeChoices(): array
    {
        return array_map(
            static fn (array $config): string => $config['label'],
            self::STAR_RANGES,
        );
    }

    /**
     * @return list<int>
     */
    public function maxRepositoryChoices(): array
    {
        return self::MAX_REPOSITORY_OPTIONS;
    }

    public function normalizeStarRangeKey(?string $starRangeKey): string
    {
        return $this->resolvedStarRangeScope($starRangeKey)->key;
    }

    public function normalizeMaxRepositories(int|string|null $maxRepositories): int
    {
        $value = (int) $maxRepositories;

        return in_array($value, self::MAX_REPOSITORY_OPTIONS, true)
            ? $value
            : self::DEFAULT_MAX_REPOSITORIES;
    }

    public function starRangeLabel(string $starRangeKey): string
    {
        return $this->resolvedStarRangeScope($starRangeKey)->label;
    }

    public function describeScope(string $starRangeKey, int $maxRepositories): string
    {
        return sprintf(
            '%s, up to %s repositories',
            $this->resolvedStarRangeScope($starRangeKey)->label,
            number_format($this->normalizeMaxRepositories($maxRepositories)),
        );
    }

    public function resolvedStarRangeScope(?string $starRangeKey): ResolvedStarRangeScope
    {
        $normalizedKey = is_string($starRangeKey) && isset(self::STAR_RANGES[$starRangeKey])
            ? $starRangeKey
            : self::DEFAULT_STAR_RANGE;
        $config = self::STAR_RANGES[$normalizedKey];

        return new ResolvedStarRangeScope(
            $normalizedKey,
            $config['label'],
            $config['min'],
            $config['max'],
            $config['seeds'],
        );
    }

    /**
     * @return array{
     *     key: string,
     *     label: string,
     *     min: int,
     *     max: int|null,
     *     seeds: list<array{min: int, max: int|null}>
     * }
     */
    public function resolveStarRangeScope(?string $starRangeKey): array
    {
        return $this->resolvedStarRangeScope($starRangeKey)->toArray();
    }

    /**
     * @return list<array{min: int, max: int|null, page: int}>
     */
    public function seedShards(string $starRangeKey): array
    {
        return $this->resolvedStarRangeScope($starRangeKey)->seedShards();
    }

    /**
     * @param list<array{id: string, name: string, stars: int|string}>|list<array<string, mixed>> $repositories
     *
     * @return list<array{min: int, max: int|null, page: int}>|null
     */
    public function splitShard(?int $minStars, ?int $maxStars, int $totalCount, array $repositories): ?array
    {
        if ($totalCount <= self::SEARCH_RESULT_SPLIT_THRESHOLD) {
            return null;
        }

        if ($maxStars !== null && $minStars !== null && $minStars < $maxStars) {
            $midpoint = intdiv($minStars + $maxStars, 2);

            if ($midpoint >= $maxStars) {
                return null;
            }

            return [
                ['min' => $midpoint + 1, 'max' => $maxStars, 'page' => 1],
                ['min' => $minStars, 'max' => $midpoint, 'page' => 1],
            ];
        }

        if ($maxStars === null && $minStars !== null && $repositories !== []) {
            $lastRepository = $repositories[array_key_last($repositories)];
            $pivot = (int) ($lastRepository['stars'] ?? 0);

            if ($pivot <= $minStars) {
                return null;
            }

            return [
                ['min' => $pivot + 1, 'max' => null, 'page' => 1],
                ['min' => $minStars, 'max' => $pivot, 'page' => 1],
            ];
        }

        return null;
    }
}
