<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Repository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class RepositorySortingTest extends WebTestCase
{
    private EntityManagerInterface $entityManager;
    private KernelBrowser $client;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();

        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $schemaTool = new SchemaTool($this->entityManager);
        $metadata = $this->entityManager->getMetadataFactory()->getAllMetadata();

        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    protected function tearDown(): void
    {
        parent::ensureKernelShutdown();
        unset($this->entityManager);
    }

    public function testIndexAppliesSortFieldAndDirectionFromQueryString(): void
    {
        $this->entityManager->persist($this->makeRepository('3', 'zeta/library', 100));
        $this->entityManager->persist($this->makeRepository('1', 'alpha/project', 300));
        $this->entityManager->persist($this->makeRepository('2', 'middle/toolkit', 200));
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', '/?sort=name&direction=asc');

        self::assertResponseIsSuccessful();
        self::assertSame(
            ['alpha/project', 'middle/toolkit', 'zeta/library'],
            $crawler->filter('tbody tr td:first-child a')->each(
                static fn ($node): string => trim($node->text())
            ),
        );
    }

    public function testFullPagePaginationStillRendersNumberedPager(): void
    {
        for ($index = 1; $index <= 25; ++$index) {
            $name = sprintf('repo-%02d', $index);
            $this->entityManager->persist($this->makeRepository((string) $index, $name, 500 - $index));
        }

        $this->entityManager->flush();

        $crawler = $this->client->request('GET', '/?sort=name&direction=asc&page=2');

        self::assertResponseIsSuccessful();
        self::assertSame(
            ['repo-21', 'repo-22', 'repo-23', 'repo-24', 'repo-25'],
            $crawler->filter('tbody tr td:first-child a')->each(
                static fn ($node): string => trim($node->text())
            ),
        );
        self::assertSame('2', trim($crawler->filter('.pagination .page-item.active .page-link')->text()));
        self::assertGreaterThanOrEqual(4, $crawler->filter('.pagination .page-link')->count());
    }

    public function testSearchWithStalePageParamClampsBackToFirstPage(): void
    {
        $this->entityManager->persist($this->makeRepository('1', 'alpha/project', 300));
        $this->entityManager->persist($this->makeRepository('2', 'beta/toolkit', 200));
        $this->entityManager->persist($this->makeRepository('3', 'gamma/library', 100));
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', '/?page=2&search=alpha&sort=name&direction=asc');

        self::assertResponseIsSuccessful();
        self::assertSame(
            ['alpha/project'],
            $crawler->filter('tbody tr td:first-child a')->each(
                static fn ($node): string => trim($node->text())
            ),
        );
        self::assertSame('1', $crawler->filter('input[name="page"]')->attr('value'));
        self::assertCount(0, $crawler->filter('.pagination .page-item.active'));
    }

    public function testFullPageUsesScopedFixedDatasetInputsForAlternateSorts(): void
    {
        $this->entityManager->persist($this->makeRepository('1', 'omega/out-of-scope', 12000));
        $this->entityManager->persist($this->makeRepository('2', 'zeta/top-scoped', 7500));
        $this->entityManager->persist($this->makeRepository('3', 'alpha/second-scoped', 5200));
        $this->entityManager->persist($this->makeRepository('4', 'beta/third-scoped', 5100));
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', '/?sort=name&direction=asc&star_range=5000_9999&max_repositories=100');

        self::assertResponseIsSuccessful();
        self::assertSame(
            ['alpha/second-scoped', 'beta/third-scoped', 'zeta/top-scoped'],
            $crawler->filter('tbody tr td:first-child a')->each(
                static fn ($node): string => trim($node->text())
            ),
        );
        self::assertSame('5000_9999', $crawler->filter('input[name="star_range"]')->attr('value'));
        self::assertSame('100', $crawler->filter('input[name="max_repositories"]')->attr('value'));
        self::assertStringContainsString(
            'Showing stored results for 5,000–9,999 stars within the top 100 repositories captured during the latest refresh.',
            $crawler->filter('[data-infinite-content]')->text()
        );
    }

    public function testEmptyStateExplainsWhenSelectedScopeHasNoStoredRepositories(): void
    {
        $this->entityManager->persist($this->makeRepository('1', 'outside/scope', 12000));
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', '/?star_range=5000_9999&max_repositories=100');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString(
            'No stored repositories are available for this scope yet. Queue a refresh to load matching repositories.',
            $crawler->filter('tbody')->text()
        );
    }

    public function testEmptyStateExplainsWhenSearchHasNoMatchesWithinScopedSet(): void
    {
        $this->entityManager->persist($this->makeRepository('2', 'beta/toolkit', 7500));
        $this->entityManager->persist($this->makeRepository('3', 'gamma/library', 5200));
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', '/?search=alpha&star_range=5000_9999&max_repositories=100');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString(
            'No stored repositories in this scope match “alpha”. Try a different search.',
            $crawler->filter('tbody')->text()
        );
    }

    public function testSearchSubmitPreservesScopeAndSortState(): void
    {
        $this->entityManager->persist($this->makeRepository('1', 'omega/out-of-scope', 12000));
        $this->entityManager->persist($this->makeRepository('2', 'alpha/inside-scope', 7500));
        $this->entityManager->persist($this->makeRepository('3', 'beta/inside-scope', 5200));
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', '/?sort=name&direction=asc&star_range=5000_9999&max_repositories=100');

        $this->client->submit($crawler->selectButton('Search')->form([
            'search' => 'alpha',
        ]));

        $crawler = $this->client->getCrawler();

        self::assertResponseIsSuccessful();
        self::assertSame('alpha', $crawler->filter('input[name="search"]')->attr('value'));
        self::assertSame('1', $crawler->filter('input[name="page"]')->attr('value'));
        self::assertSame('name', $crawler->filter('input[name="sort"]')->attr('value'));
        self::assertSame('asc', $crawler->filter('input[name="direction"]')->attr('value'));
        self::assertSame('5000_9999', $crawler->filter('input[name="star_range"]')->attr('value'));
        self::assertSame('100', $crawler->filter('input[name="max_repositories"]')->attr('value'));
        self::assertSame(
            ['alpha/inside-scope'],
            $crawler->filter('tbody tr td:first-child a')->each(
                static fn ($node): string => trim($node->text())
            ),
        );
    }

    public function testClearActionPreservesScopeAndSortStateWhileRemovingSearch(): void
    {
        $this->entityManager->persist($this->makeRepository('1', 'omega/out-of-scope', 12000));
        $this->entityManager->persist($this->makeRepository('2', 'alpha/inside-scope', 7500));
        $this->entityManager->persist($this->makeRepository('3', 'beta/inside-scope', 5200));
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', '/?search=alpha&sort=name&direction=asc&star_range=5000_9999&max_repositories=100');

        $this->client->click($crawler->selectLink('Clear')->link());
        $crawler = $this->client->getCrawler();

        self::assertResponseIsSuccessful();
        self::assertSame('', $crawler->filter('input[name="search"]')->attr('value'));
        self::assertSame('1', $crawler->filter('input[name="page"]')->attr('value'));
        self::assertSame('name', $crawler->filter('input[name="sort"]')->attr('value'));
        self::assertSame('asc', $crawler->filter('input[name="direction"]')->attr('value'));
        self::assertSame('5000_9999', $crawler->filter('input[name="star_range"]')->attr('value'));
        self::assertSame('100', $crawler->filter('input[name="max_repositories"]')->attr('value'));
        self::assertSame(
            ['alpha/inside-scope', 'beta/inside-scope'],
            $crawler->filter('tbody tr td:first-child a')->each(
                static fn ($node): string => trim($node->text())
            ),
        );
    }

    public function testScopedPageOutOfRangeClampsToFirstValidPageWhilePreservingState(): void
    {
        for ($index = 1; $index <= 10; ++$index) {
            $this->entityManager->persist($this->makeRepository(
                (string) $index,
                sprintf('repo-%02d', $index),
                9000 - $index,
            ));
        }

        for ($index = 11; $index <= 30; ++$index) {
            $this->entityManager->persist($this->makeRepository(
                (string) $index,
                sprintf('repo-%02d', $index),
                4000 - $index,
            ));
        }

        $this->entityManager->persist($this->makeRepository('100', 'outside/scope', 12000));
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', '/?page=3&sort=name&direction=asc&star_range=5000_9999&max_repositories=100');

        self::assertResponseIsSuccessful();
        self::assertSame(
            array_map(
                static fn (int $index): string => sprintf('repo-%02d', $index),
                range(1, 10),
            ),
            $crawler->filter('tbody tr td:first-child a')->each(
                static fn ($node): string => trim($node->text())
            ),
        );
        self::assertSame('1', $crawler->filter('input[name="page"]')->attr('value'));
        self::assertCount(0, $crawler->filter('.pagination .page-item.active'));
        self::assertSame('name', $crawler->filter('input[name="sort"]')->attr('value'));
        self::assertSame('asc', $crawler->filter('input[name="direction"]')->attr('value'));
        self::assertSame('5000_9999', $crawler->filter('input[name="star_range"]')->attr('value'));
        self::assertSame('100', $crawler->filter('input[name="max_repositories"]')->attr('value'));
    }

    private function makeRepository(string $id, string $name, int $stars): Repository
    {
        return new Repository(
            $id,
            $name,
            sprintf('https://github.com/%s', $name),
            sprintf('%s description', $name),
            $stars,
            new \DateTimeImmutable('2020-01-01T00:00:00+00:00'),
            new \DateTimeImmutable('2026-05-24T12:00:00+00:00'),
            new \DateTimeImmutable('2026-05-24T13:00:00+00:00'),
        );
    }
}
