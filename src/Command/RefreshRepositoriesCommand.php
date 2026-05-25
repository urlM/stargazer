<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\GitHubApiService;
use App\Service\RepositorySyncService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:repositories:refresh',
    description: 'Fetches top-starred PHP repositories from GitHub and synchronizes them with the local database.',
)]
final class RefreshRepositoriesCommand extends Command
{
    public function __construct(
        private readonly GitHubApiService $gitHubApiService,
        private readonly RepositorySyncService $repositorySyncService,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('GitHub Repository Refresh');

        try {
            $io->note('Fetching top-starred PHP repositories from GitHub...');
            $dtos = $this->gitHubApiService->fetchTopPhpRepositories();
            
            $count = count($dtos);
            $io->note(sprintf('Successfully fetched %d repositories. Starting synchronization...', $count));

            $this->repositorySyncService->sync($dtos);

            $io->success(sprintf(
                'Successfully synchronized %d repositories to the database.',
                $count
            ));

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $io->error(sprintf(
                'An error occurred during synchronization: %s',
                $e->getMessage()
            ));

            return Command::FAILURE;
        }
    }
}
