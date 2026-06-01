<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\GitHubRepositoryDTO;
use App\Entity\Repository;
use App\Repository\RepositoryRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Service responsible for synchronizing GitHub DTOs with the database.
 */
final class RepositorySyncService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly RepositoryRepository $repositoryRepository,
    ) {
    }

    /**
     * Synchronizes an array of GitHubRepositoryDTOs into the local database.
     *
     * @param array<GitHubRepositoryDTO> $dtos
     */
    public function sync(array $dtos): int
    {
        if ($dtos === []) {
            return 0;
        }

        $now = new DateTimeImmutable();
        $deduplicatedDtos = [];

        foreach ($dtos as $dto) {
            $deduplicatedDtos[$dto->id] = $dto;
        }

        $existingRepositories = $this->repositoryRepository->findBy([
            'id' => array_keys($deduplicatedDtos),
        ]);
        $existingById = [];

        foreach ($existingRepositories as $repository) {
            $existingById[$repository->getId()] = $repository;
        }

        foreach ($deduplicatedDtos as $dto) {
            $repository = $existingById[$dto->id] ?? null;

            if ($repository instanceof Repository) {
                $repository->refresh(
                    $dto->name,
                    $dto->url,
                    $dto->description,
                    $dto->stars,
                    $dto->createdAt,
                    $dto->pushedAt,
                    $now
                );
            } else {
                $repository = new Repository(
                    $dto->id,
                    $dto->name,
                    $dto->url,
                    $dto->description,
                    $dto->stars,
                    $dto->createdAt,
                    $dto->pushedAt,
                    $now
                );
                $this->entityManager->persist($repository);
            }
        }

        $this->entityManager->flush();

        return count($deduplicatedDtos);
    }
}
