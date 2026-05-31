<?php

declare(strict_types=1);

namespace App\Controller;

use App\Message\SyncRepositoriesMessage;
use App\Repository\RepositoryRepository;
use App\Service\RepositoryQueryBuilder;
use Pagerfanta\Adapter\CallbackAdapter;
use Pagerfanta\Pagerfanta;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Annotation\Route;

final class RepositoryController extends AbstractController
{
    private const DEFAULT_SORT = 'stars';
    private const DEFAULT_DIRECTION = 'desc';
    private const ALLOWED_SORTS = ['stars', 'name', 'created_at', 'pushed_at'];

    #[Route('/', name: 'app_repository_index', methods: ['GET'])]
    public function index(
        Request $request,
        RepositoryQueryBuilder $queryBuilder,
    ): Response {
        $page = $request->query->getInt('page', 1);
        $search = $request->query->getString('search', '');
        $sort = $this->normalizeSort($request->query->getString('sort', self::DEFAULT_SORT));
        $direction = $this->normalizeDirection($request->query->getString('direction', self::DEFAULT_DIRECTION));

        $qb = $queryBuilder->createListQueryBuilder($search, $sort, $direction);
        $adapter = new CallbackAdapter(
            static function () use ($qb): int {
                $countQb = clone $qb;

                return (int) $countQb
                    ->select('COUNT(repository.id)')
                    ->resetDQLPart('orderBy')
                    ->getQuery()
                    ->getSingleScalarResult();
            },
            static function (int $offset, int $length) use ($qb): iterable {
                $resultsQb = clone $qb;

                return $resultsQb
                    ->setFirstResult($offset)
                    ->setMaxResults($length)
                    ->getQuery()
                    ->getResult();
            },
        );
        $pagerfanta = new Pagerfanta($adapter);
        $pagerfanta->setMaxPerPage(20);
        $pagerfanta->setCurrentPage($page);

        $viewData = [
            'repositories' => $pagerfanta->getCurrentPageResults(),
            'pagerfanta' => $pagerfanta,
            'search' => $search,
            'page' => $page,
            'sort' => $sort,
            'direction' => $direction,
        ];

        if ($request->query->getBoolean('_fragment')) {
            return $this->render('repository/_list_content.html.twig', $viewData);
        }

        return $this->render('repository/index.html.twig', $viewData);
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
        MessageBusInterface $messageBus,
        LoggerInterface $logger,
    ): Response {
        if (!$this->isCsrfTokenValid('refresh', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('app_repository_index', [
                'page' => max(1, $request->request->getInt('page', 1)),
                'search' => $request->request->getString('search', ''),
                'sort' => $this->normalizeSort($request->request->getString('sort', self::DEFAULT_SORT)),
                'direction' => $this->normalizeDirection($request->request->getString('direction', self::DEFAULT_DIRECTION)),
            ]);
        }

        try {
            $correlationId = bin2hex(random_bytes(16));
            $messageBus->dispatch(new SyncRepositoriesMessage(
                'php',
                100,
                $correlationId,
                (new \DateTimeImmutable())->format(DATE_ATOM),
                'manual',
            ));

            $logger->info('Repository refresh queued.', [
                'correlation_id' => $correlationId,
                'triggered_by' => 'manual',
            ]);

            $this->addFlash('success', 'Repository refresh queued. The sync will run asynchronously.');
        } catch (\Throwable $exception) {
            $logger->error('Repository refresh could not be queued.', [
                'exception_class' => $exception::class,
            ]);

            $this->addFlash('error', 'Repository refresh could not be queued. Please try again.');
        }

        return $this->redirectToRoute('app_repository_index', [
            'page' => max(1, $request->request->getInt('page', 1)),
            'search' => $request->request->getString('search', ''),
            'sort' => $this->normalizeSort($request->request->getString('sort', self::DEFAULT_SORT)),
            'direction' => $this->normalizeDirection($request->request->getString('direction', self::DEFAULT_DIRECTION)),
        ]);
    }

    private function normalizeSort(string $sort): string
    {
        return in_array($sort, self::ALLOWED_SORTS, true) ? $sort : self::DEFAULT_SORT;
    }

    private function normalizeDirection(string $direction): string
    {
        return mb_strtolower($direction) === 'asc' ? 'asc' : 'desc';
    }
}
