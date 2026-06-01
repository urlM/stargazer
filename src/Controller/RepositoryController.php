<?php

declare(strict_types=1);

namespace App\Controller;

use App\Message\SyncRepositoriesMessage;
use App\Repository\RepositoryRepository;
use App\Service\RepositoryQueryBuilder;
use App\Service\RepositorySyncOptions;
use Pagerfanta\Adapter\FixedAdapter;
use Pagerfanta\Pagerfanta;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Annotation\Route;

final class RepositoryController extends AbstractController
{
    private const PER_PAGE = 20;
    private const DEFAULT_SORT = 'stars';
    private const DEFAULT_DIRECTION = 'desc';
    private const ALLOWED_SORTS = ['stars', 'name', 'created_at', 'pushed_at'];

    public function __construct(
        private readonly bool $stargazerTimingEnabled,
    ) {
    }

    #[Route('/', name: 'app_repository_index', methods: ['GET'])]
    public function index(
        Request $request,
        RepositoryQueryBuilder $queryBuilder,
        RepositorySyncOptions $syncOptions,
        LoggerInterface $logger,
    ): Response {
        $controllerStartedAt = microtime(true);
        $starRangeKey = $syncOptions->normalizeStarRangeKey($request->query->getString('star_range', RepositorySyncOptions::DEFAULT_STAR_RANGE));
        $maxRepositories = $syncOptions->normalizeMaxRepositories($request->query->getInt('max_repositories', RepositorySyncOptions::DEFAULT_MAX_REPOSITORIES));
        $viewData = $this->buildListingViewData(
            $request,
            $queryBuilder,
            $syncOptions,
            $starRangeKey,
            $maxRepositories,
        );
        $phaseTimings = null;
        $scopedIdDebugTimings = null;
        if (isset($viewData['timings']) && is_array($viewData['timings'])) {
            $phaseTimings = $viewData['timings'];
            unset($viewData['timings']);
        }
        if (isset($viewData['scoped_id_debug_timings']) && is_array($viewData['scoped_id_debug_timings'])) {
            $scopedIdDebugTimings = $viewData['scoped_id_debug_timings'];
            unset($viewData['scoped_id_debug_timings']);
        }
        $response = $this->render('repository/index.html.twig', $viewData);

        if ($this->stargazerTimingEnabled) {
            $logger->info('Repository index render timing.', [
                'route' => 'app_repository_index',
                'duration_ms' => (int) round((microtime(true) - $controllerStartedAt) * 1000),
                'page' => $viewData['page'],
                'search' => $viewData['search'],
                'sort' => $viewData['sort'],
                'direction' => $viewData['direction'],
                'star_range_key' => $viewData['selected_star_range'],
                'max_repositories' => $viewData['selected_max_repositories'],
                'phase_timings' => $phaseTimings,
            ]);

            $response->headers->set('X-Stargazer-Controller-Ms', (string) ((int) round((microtime(true) - $controllerStartedAt) * 1000)));
            if (is_array($phaseTimings)) {
                $response->headers->set('X-Stargazer-Resolve-Scope-Ids-Ms', (string) ((int) ($phaseTimings['resolve_scope_ids_ms'] ?? 0)));
                $response->headers->set('X-Stargazer-Count-Ms', (string) ((int) ($phaseTimings['count_ms'] ?? 0)));
                $response->headers->set('X-Stargazer-Rows-Ms', (string) ((int) ($phaseTimings['rows_ms'] ?? 0)));
                $response->headers->set('X-Stargazer-Total-Ms', (string) ((int) ($phaseTimings['total_ms'] ?? 0)));
            }
            if (is_array($scopedIdDebugTimings)) {
                $response->headers->set('X-Stargazer-Scoped-Ids-Cache-Hit', (string) ((int) (($scopedIdDebugTimings['cache_hit'] ?? false) ? 1 : 0)));
                $response->headers->set('X-Stargazer-Scoped-Ids-Dataset-Version-Ms', (string) ((int) ($scopedIdDebugTimings['dataset_version_ms'] ?? 0)));
                $response->headers->set('X-Stargazer-Scoped-Ids-Cache-Lookup-Ms', (string) ((int) ($scopedIdDebugTimings['cache_lookup_ms'] ?? 0)));
                $response->headers->set('X-Stargazer-Scoped-Ids-Build-Query-Ms', (string) ((int) ($scopedIdDebugTimings['build_query_ms'] ?? 0)));
                $response->headers->set('X-Stargazer-Scoped-Ids-Query-Execute-Ms', (string) ((int) ($scopedIdDebugTimings['query_execute_ms'] ?? 0)));
                $response->headers->set('X-Stargazer-Scoped-Ids-Hydration-Ms', (string) ((int) ($scopedIdDebugTimings['hydration_ms'] ?? 0)));
                $response->headers->set('X-Stargazer-Scoped-Ids-Total-Ms', (string) ((int) ($scopedIdDebugTimings['total_ms'] ?? 0)));
                $response->headers->set('X-Stargazer-Scoped-Ids-Resolved-Count', (string) ((int) ($scopedIdDebugTimings['resolved_count'] ?? 0)));
            }
        }

        return $response;
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
        RepositorySyncOptions $syncOptions,
        LoggerInterface $logger,
    ): Response {
        if (!$this->isCsrfTokenValid('refresh', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('app_repository_index', [
                'page' => max(1, $request->request->getInt('page', 1)),
                'search' => $request->request->getString('search', ''),
                'sort' => $this->normalizeSort($request->request->getString('sort', self::DEFAULT_SORT)),
                'direction' => $this->normalizeDirection($request->request->getString('direction', self::DEFAULT_DIRECTION)),
                'star_range' => $syncOptions->normalizeStarRangeKey($request->request->getString('star_range', RepositorySyncOptions::DEFAULT_STAR_RANGE)),
                'max_repositories' => $syncOptions->normalizeMaxRepositories($request->request->getInt('max_repositories', RepositorySyncOptions::DEFAULT_MAX_REPOSITORIES)),
            ]);
        }

        try {
            $correlationId = bin2hex(random_bytes(16));
            $starRangeKey = $syncOptions->normalizeStarRangeKey($request->request->getString('star_range', RepositorySyncOptions::DEFAULT_STAR_RANGE));
            $maxRepositories = $syncOptions->normalizeMaxRepositories($request->request->getInt('max_repositories', RepositorySyncOptions::DEFAULT_MAX_REPOSITORIES));
            $messageBus->dispatch(new SyncRepositoriesMessage(
                'php',
                $maxRepositories,
                $correlationId,
                (new \DateTimeImmutable())->format(DATE_ATOM),
                'manual',
                $starRangeKey,
            ));

            $logger->info('Repository refresh queued.', [
                'correlation_id' => $correlationId,
                'triggered_by' => 'manual',
                'star_range_key' => $starRangeKey,
                'max_repositories' => $maxRepositories,
            ]);

            $this->addFlash(
                'success',
                sprintf(
                    'Repository refresh queued for %s. The sync will run asynchronously.',
                    $syncOptions->describeScope($starRangeKey, $maxRepositories),
                ),
            );
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
            'star_range' => $syncOptions->normalizeStarRangeKey($request->request->getString('star_range', RepositorySyncOptions::DEFAULT_STAR_RANGE)),
            'max_repositories' => $syncOptions->normalizeMaxRepositories($request->request->getInt('max_repositories', RepositorySyncOptions::DEFAULT_MAX_REPOSITORIES)),
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

    /**
     * @return array{
     *     repositories: iterable<array{id: string, name: string, stars: int|string}>,
     *     pagerfanta: Pagerfanta<array{id: string, name: string, stars: int|string}>|null,
     *     search: string,
     *     page: int,
     *     sort: string,
     *     direction: string,
     *     selected_star_range: string,
     *     selected_max_repositories: int,
     *     star_range_choices: array<string, string>,
     *     max_repository_choices: list<int>,
     *     pagination_pages: list<int|null>
     * }
     */
    private function buildListingViewData(
        Request $request,
        RepositoryQueryBuilder $queryBuilder,
        RepositorySyncOptions $syncOptions,
        string $starRangeKey,
        int $maxRepositories,
    ): array {
        $timings = [
            'resolve_scope_ids_ms' => 0,
            'count_ms' => 0,
            'rows_ms' => 0,
            'total_ms' => 0,
        ];
        $methodStartedAt = microtime(true);
        $search = $request->query->getString('search', '');
        $sort = $this->normalizeSort($request->query->getString('sort', self::DEFAULT_SORT));
        $direction = $this->normalizeDirection($request->query->getString('direction', self::DEFAULT_DIRECTION));
        $scope = $syncOptions->resolvedStarRangeScope($starRangeKey);

        $phaseStartedAt = microtime(true);
        $scopedIdDebugTimings = null;
        $scopedRepositoryIds = $queryBuilder->resolveScopedRepositoryIds($scope, $maxRepositories, $scopedIdDebugTimings);
        $timings['resolve_scope_ids_ms'] = (int) round((microtime(true) - $phaseStartedAt) * 1000);

        $page = max(1, $request->query->getInt('page', 1));

        $phaseStartedAt = microtime(true);
        $totalResults = $queryBuilder->countRepositories($search, $scope, $maxRepositories, $scopedRepositoryIds);
        $timings['count_ms'] = (int) round((microtime(true) - $phaseStartedAt) * 1000);

        $lastPage = max(1, (int) ceil($totalResults / self::PER_PAGE));
        $page = min($page, $lastPage);
        $offset = ($page - 1) * self::PER_PAGE;

        $phaseStartedAt = microtime(true);
        $repositories = $queryBuilder->findRepositoryListItems(
            $search,
            $sort,
            $direction,
            self::PER_PAGE,
            $offset,
            $scope,
            $maxRepositories,
            $scopedRepositoryIds,
        );
        $timings['rows_ms'] = (int) round((microtime(true) - $phaseStartedAt) * 1000);
        $pagerfanta = new Pagerfanta(new FixedAdapter($totalResults, $repositories));
        $pagerfanta->setMaxPerPage(self::PER_PAGE);
        $pagerfanta->setCurrentPage($page);
        $timings['total_ms'] = (int) round((microtime(true) - $methodStartedAt) * 1000);

        $viewData = [
            'repositories' => $pagerfanta->getCurrentPageResults(),
            'pagerfanta' => $pagerfanta,
            'search' => $search,
            'page' => $page,
            'sort' => $sort,
            'direction' => $direction,
            'selected_star_range' => $starRangeKey,
            'selected_max_repositories' => $maxRepositories,
            'star_range_choices' => $syncOptions->starRangeChoices(),
            'max_repository_choices' => $syncOptions->maxRepositoryChoices(),
            'pagination_pages' => $this->buildPaginationPages($page, $lastPage),
        ];

        if ($this->stargazerTimingEnabled) {
            $viewData['timings'] = $timings;
            if (is_array($scopedIdDebugTimings)) {
                $viewData['scoped_id_debug_timings'] = $scopedIdDebugTimings;
            }
        }

        return $viewData;
    }

    /**
     * @return list<int|null>
     */
    private function buildPaginationPages(int $currentPage, int $lastPage): array
    {
        if ($lastPage <= 7) {
            return range(1, $lastPage);
        }

        $pages = [1];
        $windowStart = max(2, $currentPage - 1);
        $windowEnd = min($lastPage - 1, $currentPage + 1);

        if ($currentPage <= 3) {
            $windowEnd = 4;
        }

        if ($currentPage >= $lastPage - 2) {
            $windowStart = $lastPage - 3;
        }

        if ($windowStart > 2) {
            $pages[] = null;
        }

        foreach (range($windowStart, $windowEnd) as $page) {
            $pages[] = $page;
        }

        if ($windowEnd < $lastPage - 1) {
            $pages[] = null;
        }

        $pages[] = $lastPage;

        return $pages;
    }
}
