<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\SyncLog;
use App\Message\SyncRepositoriesMessage;
use App\Repository\SyncLogRepository;
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
        private readonly SyncLogRepository $syncLogRepository,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(SyncRepositoriesMessage $message): void
    {
        $startedAt = microtime(true);
        $syncLog = $this->startSyncLog($message);

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
            $syncLog
                ->setStatus('success')
                ->setDurationMs((int) round((microtime(true) - $startedAt) * 1000))
                ->setError(null);
            $this->syncLogRepository->save($syncLog, true);

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

            $syncLog
                ->setStatus('failed')
                ->setDurationMs((int) round((microtime(true) - $startedAt) * 1000))
                ->setError($e->getMessage());
            $this->syncLogRepository->save($syncLog, true);

            throw $e;
        }
    }

    private function startSyncLog(SyncRepositoriesMessage $message): SyncLog
    {
        $existingLog = $this->syncLogRepository->findOneByCorrelationId($message->correlationId);

        if ($existingLog instanceof SyncLog) {
            $existingLog
                ->setStatus('running')
                ->setRetryCount($existingLog->getRetryCount() + 1)
                ->setError(null)
                ->setDurationMs(null);
            $this->syncLogRepository->save($existingLog, true);

            return $existingLog;
        }

        $syncLog = new SyncLog(
            $message->correlationId,
            'running',
            $message->triggeredBy,
            $this->queuedAt($message->queuedAt),
        );

        $this->syncLogRepository->save($syncLog, true);

        return $syncLog;
    }

    private function queuedAt(string $queuedAt): \DateTimeImmutable
    {
        try {
            return new \DateTimeImmutable($queuedAt);
        } catch (\Exception) {
            return new \DateTimeImmutable();
        }
    }
}
