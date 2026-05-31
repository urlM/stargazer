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
