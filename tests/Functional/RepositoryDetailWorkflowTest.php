<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Repository;
use App\Service\GitHubApiService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class RepositoryDetailWorkflowTest extends WebTestCase
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

    public function testRepositoryDetailPageShowsMetadataAndNavigation(): void
    {
        $repository = new Repository(
            '458058',
            'symfony/symfony',
            'https://github.com/symfony/symfony',
            'The Symfony PHP framework.',
            30418,
            new \DateTimeImmutable('2011-01-12T15:38:48+00:00'),
            new \DateTimeImmutable('2026-05-24T11:15:00+00:00'),
            new \DateTimeImmutable('2026-05-24T18:30:00+00:00'),
        );

        $this->entityManager->persist($repository);
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', '/repository/458058');

        self::assertResponseIsSuccessful();
        self::assertSame('symfony/symfony | Stargazer', $crawler->filter('title')->text());
        self::assertGreaterThan(0, $crawler->selectLink('Back to repository list')->count());
    }

    public function testRepositoryDetailPageReturnsGraceful404WhenMissing(): void
    {
        $crawler = $this->client->request('GET', '/repository/999999999');

        self::assertResponseStatusCodeSame(404);
        self::assertSame('Repository Not Found | Stargazer', $crawler->filter('title')->text());
        self::assertStringContainsString('Repository not found', $crawler->filter('h1')->text());
        self::assertGreaterThan(0, $crawler->selectLink('Back to repository list')->count());
    }

    public function testRefreshFailureShowsSafeFlashMessage(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->expects(self::exactly(3))
            ->method('getStatusCode')
            ->willReturn(500);
        $response->expects(self::never())
            ->method('toArray');

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects(self::exactly(3))
            ->method('request')
            ->willReturn($response);

        $this->client->disableReboot();
        static::getContainer()->set(GitHubApiService::class, new GitHubApiService($httpClient, ''));

        $crawler = $this->client->request('GET', '/');
        $token = $crawler->filter('input[name="_token"]')->attr('value');

        $this->client->request('POST', '/refresh', ['_token' => $token]);
        $crawler = $this->client->followRedirect();

        self::assertResponseIsSuccessful();
        self::assertStringContainsString(
            'GitHub is temporarily unavailable. Please try refreshing again shortly.',
            $crawler->filter('.alert-danger')->text(),
        );
        self::assertStringNotContainsString('status code 500', $crawler->filter('.alert-danger')->text());
    }
}
