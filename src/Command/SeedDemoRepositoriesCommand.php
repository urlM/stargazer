<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Repository;
use App\Repository\RepositoryRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:repositories:seed-demo',
    description: 'Loads demo repositories for the persistence slice.',
)]
final class SeedDemoRepositoriesCommand extends Command
{
    /**
     * @var list<array{
     *     id: string,
     *     name: string,
     *     url: string,
     *     description: ?string,
     *     stars: int,
     *     createdAt: string,
     *     pushedAt: string
     * }>
     */
    private const DEMO_REPOSITORIES = [
        [
            'id' => '255523',
            'name' => 'laravel/framework',
            'url' => 'https://github.com/laravel/framework',
            'description' => 'The Laravel Framework.',
            'stars' => 33114,
            'createdAt' => '2011-06-08T20:52:35+00:00',
            'pushedAt' => '2026-05-24T10:42:00+00:00',
        ],
        [
            'id' => '458058',
            'name' => 'symfony/symfony',
            'url' => 'https://github.com/symfony/symfony',
            'description' => 'The Symfony PHP framework.',
            'stars' => 30418,
            'createdAt' => '2011-01-12T15:38:48+00:00',
            'pushedAt' => '2026-05-24T11:15:00+00:00',
        ],
        [
            'id' => '685842',
            'name' => 'guzzle/guzzle',
            'url' => 'https://github.com/guzzle/guzzle',
            'description' => 'A PHP HTTP client library.',
            'stars' => 23407,
            'createdAt' => '2011-03-26T03:53:27+00:00',
            'pushedAt' => '2026-05-22T17:04:00+00:00',
        ],
        [
            'id' => '1207065',
            'name' => 'spatie/laravel-permission',
            'url' => 'https://github.com/spatie/laravel-permission',
            'description' => 'Associate users with roles and permissions.',
            'stars' => 12548,
            'createdAt' => '2015-09-14T07:55:58+00:00',
            'pushedAt' => '2026-05-20T09:23:00+00:00',
        ],
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly RepositoryRepository $repositories,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $syncedAt = new DateTimeImmutable('now');
        $createdCount = 0;
        $updatedCount = 0;

        foreach (self::DEMO_REPOSITORIES as $record) {
            $repository = $this->repositories->find($record['id']);

            if ($repository instanceof Repository) {
                $repository->refresh(
                    $record['name'],
                    $record['url'],
                    $record['description'],
                    $record['stars'],
                    new DateTimeImmutable($record['createdAt']),
                    new DateTimeImmutable($record['pushedAt']),
                    $syncedAt,
                );
                ++$updatedCount;

                continue;
            }

            $this->entityManager->persist(new Repository(
                $record['id'],
                $record['name'],
                $record['url'],
                $record['description'],
                $record['stars'],
                new DateTimeImmutable($record['createdAt']),
                new DateTimeImmutable($record['pushedAt']),
                $syncedAt,
            ));
            ++$createdCount;
        }

        $this->entityManager->flush();

        $io->success(sprintf(
            'Demo repositories synced. Created: %d, updated: %d.',
            $createdCount,
            $updatedCount,
        ));

        return Command::SUCCESS;
    }
}
