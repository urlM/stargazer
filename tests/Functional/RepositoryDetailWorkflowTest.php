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

        $this->client->request('POST', '/refresh', ['_token' => $token]);
        $transport = static::getContainer()->get('messenger.transport.async');
        \assert($transport instanceof InMemoryTransport);
        $sentMessages = $transport->getSent();
        $crawler = $this->client->followRedirect();

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Repository refresh queued. The sync will run asynchronously.', $crawler->filter('.alert-success')->text());
        self::assertCount(1, $sentMessages);

        $message = $sentMessages[0]->getMessage();

        self::assertInstanceOf(SyncRepositoriesMessage::class, $message);
        self::assertSame('php', $message->language);
        self::assertSame(100, $message->limit);
        self::assertNotSame('', $message->correlationId);
    }
}
