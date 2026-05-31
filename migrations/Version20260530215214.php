<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260530215214 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create sync_logs table for operational sync tracking.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            $this->connection->getDatabasePlatform()->getName() !== 'mysql',
            'Migration can only be executed safely on "mysql".',
        );

        $this->addSql("CREATE TABLE sync_logs (id BIGINT AUTO_INCREMENT NOT NULL, correlation_id VARCHAR(36) NOT NULL, status VARCHAR(20) NOT NULL, retry_count INT DEFAULT 0 NOT NULL, triggered_by VARCHAR(50) NOT NULL, duration_ms INT DEFAULT NULL, rate_limit_remaining INT DEFAULT NULL, error LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', INDEX idx_sync_logs_correlation_id (correlation_id), INDEX idx_sync_logs_status (status), INDEX idx_sync_logs_created_at (created_at), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            $this->connection->getDatabasePlatform()->getName() !== 'mysql',
            'Migration can only be executed safely on "mysql".',
        );

        $this->addSql('DROP TABLE sync_logs');
    }
}
