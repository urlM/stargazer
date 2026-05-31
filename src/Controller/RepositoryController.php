<?php

declare(strict_types=1);

namespace App\Controller;

use App\Exception\GitHub\GitHubApiException;
use App\Exception\GitHub\GitHubInvalidResponseException;
use App\Exception\GitHub\GitHubRateLimitException;
use App\Exception\GitHub\GitHubTimeoutException;
use App\Exception\GitHub\GitHubUnavailableException;
use App\Repository\RepositoryRepository;
use App\Service\GitHubApiService;
use App\Service\RepositorySyncService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

final class RepositoryController extends AbstractController
{
    #[Route('/', name: 'app_repository_index', methods: ['GET'])]
    public function index(RepositoryRepository $repositoryRepository): Response
    {
        return $this->render('repository/index.html.twig', [
            'repositories' => $repositoryRepository->findTopRepositories(),
        ]);
    }

    #[Route('/repository/{id}', name: 'app_repository_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(string $id, RepositoryRepository $repositoryRepository): Response
    {
        $repository = $repositoryRepository->find($id);

        if ($repository === null) {
            return $this->render('repository/not_found.html.twig', [
                'repositoryId' => $id,
            ], new Response(status: Response::HTTP_NOT_FOUND));
        }

        return $this->render('repository/show.html.twig', [
            'repository' => $repository,
        ]);
    }

    #[Route('/refresh', name: 'app_repository_refresh', methods: ['POST'])]
    public function refresh(
        Request $request,
        GitHubApiService $apiService,
        RepositorySyncService $syncService,
        LoggerInterface $logger,
    ): Response {
        if (!$this->isCsrfTokenValid('refresh', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('app_repository_index');
        }

        try {
            $dtos = $apiService->fetchTopPhpRepositories();
            $syncService->sync($dtos);
            $this->addFlash('success', sprintf('Successfully synchronized %d repositories.', count($dtos)));
        } catch (GitHubApiException $exception) {
            $logger->warning('Repository refresh failed during GitHub API request.', [
                'exception_class' => $exception::class,
                'status_code' => $exception->getStatusCode(),
                'retryable' => $exception->isRetryable(),
            ]);

            $this->addFlash('error', $this->githubFailureMessage($exception));
        } catch (\Throwable $exception) {
            $logger->error('Repository refresh failed unexpectedly.', [
                'exception_class' => $exception::class,
            ]);

            $this->addFlash('error', 'Repository refresh failed unexpectedly. Please try again.');
        }

        return $this->redirectToRoute('app_repository_index');
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
