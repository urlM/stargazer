<?php

declare(strict_types=1);

namespace App\Service;

final class ResolvedStarRangeScope
{
    /**
     * @param list<array{min: int, max: int|null}> $seeds
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly int $min,
        public readonly ?int $max,
        public readonly array $seeds,
    ) {
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
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'min' => $this->min,
            'max' => $this->max,
            'seeds' => $this->seeds,
        ];
    }

    /**
     * @return list<array{min: int, max: int|null, page: int}>
     */
    public function seedShards(): array
    {
        $shards = [];

        foreach ($this->seeds as $seed) {
            $shards[] = [
                'min' => $seed['min'],
                'max' => $seed['max'],
                'page' => 1,
            ];
        }

        return $shards;
    }
}
