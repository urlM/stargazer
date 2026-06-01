<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Repository;
use App\Message\SyncRepositoriesMessage;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

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

    public function testRefreshQueuesAsyncSyncAndShowsSuccessFlash(): void
    {
        $crawler = $this->client->request('GET', '/');
        $token = $crawler->filter('input[name="_token"]')->attr('value');

        $this->client->request('POST', '/refresh', [
            '_token' => $token,
            'star_range' => '1000_4999',
            'max_repositories' => 500,
        ]);
        $transport = static::getContainer()->get('messenger.transport.async');
        \assert($transport instanceof InMemoryTransport);
        $sentMessages = $transport->getSent();
        $crawler = $this->client->followRedirect();

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Repository refresh queued for 1,000–4,999 stars, up to 500 repositories.', $crawler->filter('.alert-success')->text());
        self::assertCount(1, $sentMessages);

        $message = $sentMessages[0]->getMessage();

        self::assertInstanceOf(SyncRepositoriesMessage::class, $message);
        self::assertSame('php', $message->language);
        self::assertSame(500, $message->maxRepositories);
        self::assertNotSame('', $message->correlationId);
        self::assertSame('manual', $message->triggeredBy);
        self::assertSame('1000_4999', $message->starRangeKey);
        self::assertNull($message->remainingRepositories);
        self::assertSame(0, $message->syncedCount);
        self::assertSame([], $message->pendingShards);
    }

    public function testRefreshRedirectImmediatelyUsesSelectedScopeAgainstStoredRepositories(): void
    {
        for ($index = 1; $index <= 100; ++$index) {
            $this->entityManager->persist($this->makeRepository(
                (string) $index,
                sprintf('repo-%03d', $index),
                9999 - $index,
            ));
        }

        $this->entityManager->persist($this->makeRepository('101', 'aaa/excluded-scoped', 5000));
        $this->entityManager->persist($this->makeRepository('102', 'aab/out-of-scope', 12000));
        $this->entityManager->flush();

        $crawler = $this->client->request('GET', '/');
        $token = $crawler->filter('input[name="_token"]')->attr('value');

        $this->client->request('POST', '/refresh', [
            '_token' => $token,
            'sort' => 'name',
            'direction' => 'asc',
            'star_range' => '5000_9999',
            'max_repositories' => 100,
        ]);

        $transport = static::getContainer()->get('messenger.transport.async');
        \assert($transport instanceof InMemoryTransport);
        $sentMessages = $transport->getSent();
        $crawler = $this->client->followRedirect();

        self::assertResponseIsSuccessful();
        self::assertCount(1, $sentMessages);
        self::assertSame('5000_9999', $crawler->filter('input[name="star_range"]')->attr('value'));
        self::assertSame('100', $crawler->filter('input[name="max_repositories"]')->attr('value'));
        self::assertStringContainsString('Repository refresh queued for', $crawler->filter('.alert-success')->text());
        self::assertSame(
            array_map(
                static fn (int $index): string => sprintf('repo-%03d', $index),
                range(1, 20),
            ),
            $crawler->filter('tbody tr td:first-child a')->each(
                static fn ($node): string => trim($node->text())
            ),
        );
        self::assertStringNotContainsString('aaa/excluded-scoped', $crawler->filter('tbody')->text());
        self::assertStringNotContainsString('aab/out-of-scope', $crawler->filter('tbody')->text());
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
