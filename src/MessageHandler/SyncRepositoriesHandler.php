<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\SyncRepositoriesMessage;
use App\Service\GitHubApiService;
use App\Service\RepositorySyncService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class SyncRepositoriesHandler
{
    public function __construct(
        private readonly GitHubApiService $githubApiService,
        private readonly RepositorySyncService $repositorySyncService,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(SyncRepositoriesMessage $message): void
    {
        $startedAt = microtime(true);

        $this->logger->info('Starting async repository sync.', [
            'correlation_id' => $message->correlationId,
            'language' => $message->language,
            'limit' => $message->limit,
        ]);

        try {
            // Fetch repositories from GitHub
            $dtos = $this->githubApiService->fetchTopPhpRepositories($message->limit);

            $this->logger->info('Fetched repositories from GitHub.', [
                'correlation_id' => $message->correlationId,
                'count' => count($dtos),
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ]);

            // Persist to database
            $this->repositorySyncService->sync($dtos);

            $this->logger->info('Async repository sync completed.', [
                'correlation_id' => $message->correlationId,
                'language' => $message->language,
                'limit' => $message->limit,
                'synced_count' => count($dtos),
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Async repository sync failed.', [
                'correlation_id' => $message->correlationId,
                'language' => $message->language,
                'limit' => $message->limit,
                'exception_class' => $e::class,
                'exception_message' => $e->getMessage(),
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ]);

            throw $e;
        }
    }
}
