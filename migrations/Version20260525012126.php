<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260525012126 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create repositories table for persisted GitHub repository data.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            $this->connection->getDatabasePlatform()->getName() !== 'mysql',
            'Migration can only be executed safely on "mysql".',
        );

        $this->addSql('CREATE TABLE repositories (id BIGINT UNSIGNED NOT NULL, name VARCHAR(255) NOT NULL, url VARCHAR(500) NOT NULL, description LONGTEXT DEFAULT NULL, stars INT UNSIGNED NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', pushed_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', synced_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE INDEX idx_repositories_stars ON repositories (stars)');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            $this->connection->getDatabasePlatform()->getName() !== 'mysql',
            'Migration can only be executed safely on "mysql".',
        );

        $this->addSql('DROP TABLE repositories');
    }
}
