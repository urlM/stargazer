<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260531052000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store sync scope and progress on sync logs.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            $this->connection->getDatabasePlatform()->getName() !== 'mysql',
            'Migration can only be executed safely on "mysql".',
        );

        $this->addSql("ALTER TABLE sync_logs ADD star_range_key VARCHAR(50) DEFAULT 'all' NOT NULL, ADD max_repositories INT DEFAULT NULL, ADD synced_count INT DEFAULT 0 NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            $this->connection->getDatabasePlatform()->getName() !== 'mysql',
            'Migration can only be executed safely on "mysql".',
        );

        $this->addSql('ALTER TABLE sync_logs DROP star_range_key, DROP max_repositories, DROP synced_count');
    }
}
