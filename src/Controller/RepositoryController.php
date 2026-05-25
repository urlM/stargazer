<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\GitHubApiService;
use App\Service\RepositorySyncService;
use App\Repository\RepositoryRepository;
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
            throw $this->createNotFoundException(sprintf('Repository with id "%s" was not found.', $id));
        }

        return $this->render('repository/show.html.twig', [
            'repository' => $repository,
        ]);
    }

    #[Route('/refresh', name: 'app_repository_refresh', methods: ['POST'])]
    public function refresh(
        Request $request,
        GitHubApiService $apiService,
        RepositorySyncService $syncService
    ): Response {
        if (!$this->isCsrfTokenValid('refresh', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('app_repository_index');
        }

        try {
            $dtos = $apiService->fetchTopPhpRepositories();
            $syncService->sync($dtos);
            $this->addFlash('success', sprintf('Successfully synchronized %d repositories.', count($dtos)));
        } catch (\Throwable $e) {
            $this->addFlash('error', 'Sync failed: ' . $e->getMessage());
        }

        return $this->redirectToRoute('app_repository_index');
    }
}
