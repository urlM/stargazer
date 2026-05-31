<?php

declare(strict_types=1);

namespace App\Command;

use App\Exception\GitHub\GitHubApiException;
use App\Exception\GitHub\GitHubInvalidResponseException;
use App\Exception\GitHub\GitHubRateLimitException;
use App\Exception\GitHub\GitHubTimeoutException;
use App\Exception\GitHub\GitHubUnavailableException;
use App\Service\GitHubApiService;
use App\Service\RepositorySyncService;
use Psr\Log\LoggerInterface;
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
        private readonly LoggerInterface $logger,
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
        } catch (GitHubApiException $exception) {
            $this->logger->warning('Repository refresh command failed during GitHub API request.', [
                'exception_class' => $exception::class,
                'status_code' => $exception->getStatusCode(),
                'retryable' => $exception->isRetryable(),
            ]);

            $io->error($this->githubFailureMessage($exception));

            return Command::FAILURE;
        } catch (\Throwable $exception) {
            $this->logger->error('Repository refresh command failed unexpectedly.', [
                'exception_class' => $exception::class,
            ]);

            $io->error('Repository refresh failed unexpectedly. Please try again.');

            return Command::FAILURE;
        }
    }

    private function githubFailureMessage(GitHubApiException $exception): string
    {
        return match (true) {
            $exception instanceof GitHubRateLimitException => 'GitHub rate limit was reached. Please wait a few minutes before refreshing again.',
            $exception instanceof GitHubTimeoutException => 'GitHub did not respond in time. Please try refreshing again.',
            $exception instanceof GitHubUnavailableException => 'GitHub is temporarily unavailable. Please try refreshing again shortly.',
            $exception instanceof GitHubInvalidResponseException => 'GitHub returned an unexpected response. Please try refreshing again later.',
            default => 'GitHub refresh failed. Please try again later.',
        };
    }
}
