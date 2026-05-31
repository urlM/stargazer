<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Repository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class RepositoryInfiniteScrollTest extends WebTestCase
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

    public function testFragmentResponseReturnsSlimPayloadWithPreservedState(): void
    {
        for ($index = 1; $index <= 25; ++$index) {
            $name = sprintf('repo-%02d', $index);
            $this->entityManager->persist($this->makeRepository((string) $index, $name, 500 - $index));
        }

        $this->entityManager->flush();

        $crawler = $this->client->request('GET', '/?_fragment=1&page=2&sort=name&direction=asc&search=repo');

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('<html', (string) $this->client->getResponse()->getContent());
        self::assertCount(0, $crawler->filter('[data-infinite-content]'));
        self::assertCount(0, $crawler->filter('[data-pagination]'));
        self::assertCount(0, $crawler->filter('thead'));
        self::assertCount(5, $crawler->filter('tbody tr[data-repository-id]'));
        self::assertSame(
            ['repo-21', 'repo-22', 'repo-23', 'repo-24', 'repo-25'],
            $crawler->filter('tbody tr td:first-child a')->each(
                static fn ($node): string => trim($node->text())
            ),
        );
        self::assertSame('', $crawler->filter('[data-infinite-fragment]')->attr('data-next-page-url'));
    }

    public function testFragmentResponseExposesNextPageUrlWithoutTotalPagerMarkup(): void
    {
        for ($index = 1; $index <= 45; ++$index) {
            $name = sprintf('repo-%02d', $index);
            $this->entityManager->persist($this->makeRepository((string) $index, $name, 500 - $index));
        }

        $this->entityManager->flush();

        $crawler = $this->client->request('GET', '/?_fragment=1&page=2&sort=name&direction=asc&search=repo');

        self::assertResponseIsSuccessful();
        self::assertCount(20, $crawler->filter('tbody tr[data-repository-id]'));
        self::assertSame(
            '/?search=repo&page=3&sort=name&direction=asc',
            $crawler->filter('[data-infinite-fragment]')->attr('data-next-page-url'),
        );
        self::assertCount(0, $crawler->filter('.pagination'));
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
