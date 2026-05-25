<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Repository;
use App\Repository\RepositoryRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class RepositoryRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private RepositoryRepository $repositories;

    protected function setUp(): void
    {
        self::bootKernel();

        $container = static::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->repositories = $container->get(RepositoryRepository::class);

        $metadata = $this->entityManager->getMetadataFactory()->getAllMetadata();
        $schemaTool = new SchemaTool($this->entityManager);

        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    protected function tearDown(): void
    {
        parent::ensureKernelShutdown();
        unset($this->entityManager, $this->repositories);
    }

    public function testFindTopRepositoriesOrdersResultsByStarsDescending(): void
    {
        $this->repositories->save($this->makeRepository('255523', 'laravel/framework', 33114));
        $this->repositories->save($this->makeRepository('685842', 'guzzle/guzzle', 23407));
        $this->repositories->save($this->makeRepository('458058', 'symfony/symfony', 30418), true);

        $this->entityManager->clear();

        $ordered = $this->repositories->findTopRepositories();

        self::assertSame(
            ['255523', '458058', '685842'],
            array_map(static fn (Repository $repository): string => $repository->getId(), $ordered),
        );
    }

    public function testNaturalKeyFlowUpdatesExistingRepositoryWithoutDuplicates(): void
    {
        $this->repositories->save($this->makeRepository('458058', 'symfony/symfony', 30418), true);

        $this->entityManager->clear();

        $stored = $this->repositories->find('458058');

        self::assertInstanceOf(Repository::class, $stored);

        $stored->refresh(
            'symfony/symfony',
            'https://github.com/symfony/symfony',
            'The Symfony PHP framework.',
            30499,
            new DateTimeImmutable('2011-01-12T15:38:48+00:00'),
            new DateTimeImmutable('2026-05-24T11:15:00+00:00'),
            new DateTimeImmutable('2026-05-24T18:30:00+00:00'),
        );
        $this->entityManager->flush();
        $this->entityManager->clear();

        $updated = $this->repositories->find('458058');

        self::assertInstanceOf(Repository::class, $updated);
        self::assertSame(1, $this->repositories->count([]));
        self::assertSame(30499, $updated->getStars());
        self::assertSame('The Symfony PHP framework.', $updated->getDescription());
    }

    private function makeRepository(string $id, string $name, int $stars): Repository
    {
        return new Repository(
            $id,
            $name,
            sprintf('https://github.com/%s', $name),
            sprintf('%s description', $name),
            $stars,
            new DateTimeImmutable('2020-01-01T00:00:00+00:00'),
            new DateTimeImmutable('2026-05-24T12:00:00+00:00'),
            new DateTimeImmutable('2026-05-24T13:00:00+00:00'),
        );
    }
}
