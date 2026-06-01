<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

final class RepositoryCacheVersionManager
{
    private const VERSION_KEY = 'repository_query_builder.version';

    public function __construct(
        #[Autowire(service: 'cache.app')]
        private readonly CacheInterface $cache,
    ) {
    }

    public function currentVersion(): string
    {
        return $this->cache->get(self::VERSION_KEY, static function (ItemInterface $item): string {
            // No TTL: repository listing caches become stale only when sync writes complete.
            return bin2hex(random_bytes(8));
        });
    }

    public function bumpVersion(): string
    {
        $this->cache->delete(self::VERSION_KEY);

        return $this->currentVersion();
    }
}
