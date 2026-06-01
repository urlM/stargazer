<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\RepositoryRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: RepositoryRepository::class)]
#[ORM\Table(name: 'repositories')]
#[ORM\Index(name: 'idx_repositories_stars_id', columns: ['stars', 'id'])]
#[ORM\Index(name: 'idx_repositories_name_id', columns: ['name', 'id'])]
#[ORM\Index(name: 'idx_repositories_created_at_id', columns: ['created_at', 'id'])]
#[ORM\Index(name: 'idx_repositories_pushed_at_id', columns: ['pushed_at', 'id'])]
class Repository
{
    #[ORM\Id]
    #[ORM\Column(type: Types::BIGINT, options: ['unsigned' => true])]
    private string $id;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(length: 500)]
    private string $url;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description;

    #[ORM\Column(options: ['unsigned' => true])]
    private int $stars;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $pushedAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $syncedAt;

    public function __construct(
        string $id,
        string $name,
        string $url,
        ?string $description,
        int $stars,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $pushedAt,
        DateTimeImmutable $syncedAt,
    ) {
        $this->id = $id;
        $this->refresh($name, $url, $description, $stars, $createdAt, $pushedAt, $syncedAt);
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function getStars(): int
    {
        return $this->stars;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getPushedAt(): DateTimeImmutable
    {
        return $this->pushedAt;
    }

    public function getSyncedAt(): DateTimeImmutable
    {
        return $this->syncedAt;
    }

    public function refresh(
        string $name,
        string $url,
        ?string $description,
        int $stars,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $pushedAt,
        DateTimeImmutable $syncedAt,
    ): void {
        $this->name = $name;
        $this->url = $url;
        $this->description = $description;
        $this->stars = $stars;
        $this->createdAt = $createdAt;
        $this->pushedAt = $pushedAt;
        $this->syncedAt = $syncedAt;
    }
}
