<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SyncLogRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SyncLogRepository::class)]
#[ORM\Table(name: 'sync_logs')]
#[ORM\Index(columns: ['correlation_id'], name: 'idx_sync_logs_correlation_id')]
#[ORM\Index(columns: ['status'], name: 'idx_sync_logs_status')]
#[ORM\Index(columns: ['created_at'], name: 'idx_sync_logs_created_at')]
final class SyncLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 36)]
    private string $correlationId;

    #[ORM\Column(type: Types::STRING, length: 20)]
    private string $status; // 'pending', 'running', 'success', 'failed'

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $retryCount = 0;

    #[ORM\Column(type: Types::STRING, length: 50)]
    private string $triggeredBy; // 'manual', 'scheduled', 'webhook', 'cli'

    #[ORM\Column(type: Types::STRING, length: 50, options: ['default' => 'all'])]
    private string $starRangeKey = 'all';

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $maxRepositories = null;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $syncedCount = 0;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $durationMs = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $rateLimitRemaining = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $error = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $updatedAt;

    public function __construct(
        string $correlationId,
        string $status,
        string $triggeredBy,
        string $starRangeKey = 'all',
        ?int $maxRepositories = null,
        DateTimeImmutable $createdAt = new DateTimeImmutable(),
    ) {
        $this->correlationId = $correlationId;
        $this->status = $status;
        $this->triggeredBy = $triggeredBy;
        $this->starRangeKey = $starRangeKey;
        $this->maxRepositories = $maxRepositories;
        $this->createdAt = $createdAt;
        $this->updatedAt = $createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCorrelationId(): string
    {
        return $this->correlationId;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;
        $this->updatedAt = new DateTimeImmutable();
        return $this;
    }

    public function getRetryCount(): int
    {
        return $this->retryCount;
    }

    public function setRetryCount(int $retryCount): self
    {
        $this->retryCount = $retryCount;
        $this->updatedAt = new DateTimeImmutable();
        return $this;
    }

    public function getTriggeredBy(): string
    {
        return $this->triggeredBy;
    }

    public function getStarRangeKey(): string
    {
        return $this->starRangeKey;
    }

    public function setStarRangeKey(string $starRangeKey): self
    {
        $this->starRangeKey = $starRangeKey;
        $this->updatedAt = new DateTimeImmutable();

        return $this;
    }

    public function getMaxRepositories(): ?int
    {
        return $this->maxRepositories;
    }

    public function setMaxRepositories(?int $maxRepositories): self
    {
        $this->maxRepositories = $maxRepositories;
        $this->updatedAt = new DateTimeImmutable();

        return $this;
    }

    public function getSyncedCount(): int
    {
        return $this->syncedCount;
    }

    public function setSyncedCount(int $syncedCount): self
    {
        $this->syncedCount = $syncedCount;
        $this->updatedAt = new DateTimeImmutable();

        return $this;
    }

    public function getDurationMs(): ?int
    {
        return $this->durationMs;
    }

    public function setDurationMs(?int $durationMs): self
    {
        $this->durationMs = $durationMs;
        $this->updatedAt = new DateTimeImmutable();
        return $this;
    }

    public function getRateLimitRemaining(): ?int
    {
        return $this->rateLimitRemaining;
    }

    public function setRateLimitRemaining(?int $rateLimitRemaining): self
    {
        $this->rateLimitRemaining = $rateLimitRemaining;
        $this->updatedAt = new DateTimeImmutable();
        return $this;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function setError(?string $error): self
    {
        $this->error = $error;
        $this->updatedAt = new DateTimeImmutable();
        return $this;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
