<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\SyncLog;
use App\Message\SyncRepositoriesMessage;
use App\Repository\SyncLogRepository;
use App\Service\RepositoryCacheVersionManager;
use App\Service\GitHubApiService;
use App\Service\RepositorySyncOptions;
use App\Service\RepositorySyncService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
final class SyncRepositoriesHandler
{
    private const PAGE_SIZE = 100;

    public function __construct(
        private readonly GitHubApiService $githubApiService,
        private readonly RepositorySyncService $repositorySyncService,
        private readonly RepositorySyncOptions $syncOptions,
        private readonly RepositoryCacheVersionManager $repositoryCacheVersionManager,
        private readonly SyncLogRepository $syncLogRepository,
        private readonly MessageBusInterface $messageBus,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(SyncRepositoriesMessage $message): void
    {
        $startedAt = microtime(true);
        $syncLog = $this->startSyncLog($message);
        $remainingRepositories = $message->remainingRepositories ?? $message->maxRepositories;
        $pendingShards = $message->pendingShards !== []
            ? $message->pendingShards
            : $this->syncOptions->seedShards($message->starRangeKey);

        $this->logger->info('Starting async repository sync.', [
            'correlation_id' => $message->correlationId,
            'language' => $message->language,
            'max_repositories' => $message->maxRepositories,
            'remaining_repositories' => $remainingRepositories,
            'star_range_key' => $message->starRangeKey,
        ]);

        try {
            if ($remainingRepositories <= 0 || $pendingShards === []) {
                $this->markSyncAsSuccessful($syncLog, $message->syncedCount, $startedAt);

                return;
            }

            $currentShard = $pendingShards[0];
            $pageResult = $this->githubApiService->searchPhpRepositoriesPage(
                min(self::PAGE_SIZE, $remainingRepositories),
                $currentShard['page'],
                $currentShard['min'],
                $currentShard['max'],
            );
            $syncLog->setRateLimitRemaining($pageResult->rateLimitRemaining);

            $this->logger->info('Fetched repositories from GitHub.', [
                'correlation_id' => $message->correlationId,
                'count' => count($pageResult->repositories),
                'page' => $currentShard['page'],
                'min_stars' => $currentShard['min'],
                'max_stars' => $currentShard['max'],
                'total_count' => $pageResult->totalCount,
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ]);

            $splitShards = $this->syncOptions->splitShard(
                $currentShard['min'],
                $currentShard['max'],
                $pageResult->totalCount,
                array_map(
                    static fn ($repository): array => [
                        'id' => $repository->id,
                        'name' => $repository->name,
                        'stars' => $repository->stars,
                    ],
                    $pageResult->repositories,
                ),
            );

            if ($currentShard['page'] === 1 && $splitShards !== null) {
                if ($remainingRepositories <= count($pageResult->repositories)) {
                    $splitShards = null;
                }
            }

            if ($currentShard['page'] === 1 && $splitShards !== null) {
                array_shift($pendingShards);
                array_unshift($pendingShards, ...$splitShards);

                $syncLog->setSyncedCount($message->syncedCount);
                $this->syncLogRepository->save($syncLog, true);
                $this->dispatchFollowUpMessage($message, $remainingRepositories, $message->syncedCount, $pendingShards);

                return;
            }

            $syncedCount = $this->repositorySyncService->sync($pageResult->repositories);
            $remainingRepositories -= $syncedCount;
            $totalSyncedCount = $message->syncedCount + $syncedCount;

            if ($remainingRepositories <= 0) {
                $this->markSyncAsSuccessful($syncLog, $totalSyncedCount, $startedAt);

                return;
            }

            if ($pageResult->hasNextPage()) {
                $pendingShards[0]['page'] = $currentShard['page'] + 1;
            } else {
                array_shift($pendingShards);
            }

            $syncLog->setSyncedCount($totalSyncedCount);
            $this->syncLogRepository->save($syncLog, true);

            if ($pendingShards === []) {
                $this->markSyncAsSuccessful($syncLog, $totalSyncedCount, $startedAt);

                return;
            }

            $this->dispatchFollowUpMessage($message, $remainingRepositories, $totalSyncedCount, $pendingShards);

            $this->logger->info('Async repository sync page processed.', [
                'correlation_id' => $message->correlationId,
                'language' => $message->language,
                'max_repositories' => $message->maxRepositories,
                'remaining_repositories' => $remainingRepositories,
                'synced_count' => $totalSyncedCount,
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Async repository sync failed.', [
                'correlation_id' => $message->correlationId,
                'language' => $message->language,
                'max_repositories' => $message->maxRepositories,
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
                ->setStarRangeKey($message->starRangeKey)
                ->setMaxRepositories($message->maxRepositories)
                ->setError(null)
                ->setDurationMs(null);
            $this->syncLogRepository->save($existingLog, true);

            return $existingLog;
        }

        $syncLog = new SyncLog(
            $message->correlationId,
            'running',
            $message->triggeredBy,
            $message->starRangeKey,
            $message->maxRepositories,
            $this->queuedAt($message->queuedAt),
        );

        $syncLog->setSyncedCount($message->syncedCount);
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

    /**
     * @param list<array{min: int, max: int|null, page: int}> $pendingShards
     */
    private function dispatchFollowUpMessage(
        SyncRepositoriesMessage $message,
        int $remainingRepositories,
        int $syncedCount,
        array $pendingShards,
    ): void {
        $this->messageBus->dispatch(new SyncRepositoriesMessage(
            $message->language,
            $message->maxRepositories,
            $message->correlationId,
            $message->queuedAt,
            $message->triggeredBy,
            $message->starRangeKey,
            $remainingRepositories,
            $syncedCount,
            $pendingShards,
        ));
    }

    private function markSyncAsSuccessful(SyncLog $syncLog, int $syncedCount, float $startedAt): void
    {
        $syncLog
            ->setStatus('success')
            ->setSyncedCount($syncedCount)
            ->setDurationMs((int) round((microtime(true) - $startedAt) * 1000))
            ->setError(null);
        $this->syncLogRepository->save($syncLog, true);
        $this->repositoryCacheVersionManager->bumpVersion();
    }
}
