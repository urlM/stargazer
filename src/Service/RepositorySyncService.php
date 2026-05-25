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
    public function sync(array $dtos): void
    {
        $now = new DateTimeImmutable();

        foreach ($dtos as $dto) {
            $repository = $this->repositoryRepository->find($dto->id);

            if ($repository instanceof Repository) {
                // Update existing record
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
                // Create new record
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
    }
}