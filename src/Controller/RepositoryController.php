<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\RepositoryRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
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
}