<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\RepositorySyncOptions;
use PHPUnit\Framework\TestCase;

final class RepositorySyncOptionsTest extends TestCase
{
    public function testResolveStarRangeScopeReturnsNormalizedBoundsAndMetadata(): void
    {
        $options = new RepositorySyncOptions();

        self::assertSame([
            'key' => '5000_9999',
            'label' => '5,000â€“9,999 stars',
            'min' => 5000,
            'max' => 9999,
            'seeds' => [
                ['min' => 5000, 'max' => 9999],
            ],
        ], $options->resolveStarRangeScope('5000_9999'));

        self::assertSame([
            'key' => RepositorySyncOptions::DEFAULT_STAR_RANGE,
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
        ], $options->resolveStarRangeScope('unknown'));
    }
}
