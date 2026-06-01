<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260531034500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add composite repository sort indexes for paginated list queries.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            $this->connection->getDatabasePlatform()->getName() !== 'mysql',
            'Migration can only be executed safely on "mysql".',
        );

        $this->addSql('DROP INDEX idx_repositories_stars ON repositories');
        $this->addSql('CREATE INDEX idx_repositories_stars_id ON repositories (stars, id)');
        $this->addSql('CREATE INDEX idx_repositories_name_id ON repositories (name, id)');
        $this->addSql('CREATE INDEX idx_repositories_created_at_id ON repositories (created_at, id)');
        $this->addSql('CREATE INDEX idx_repositories_pushed_at_id ON repositories (pushed_at, id)');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            $this->connection->getDatabasePlatform()->getName() !== 'mysql',
            'Migration can only be executed safely on "mysql".',
        );

        $this->addSql('DROP INDEX idx_repositories_stars_id ON repositories');
        $this->addSql('DROP INDEX idx_repositories_name_id ON repositories');
        $this->addSql('DROP INDEX idx_repositories_created_at_id ON repositories');
        $this->addSql('DROP INDEX idx_repositories_pushed_at_id ON repositories');
        $this->addSql('CREATE INDEX idx_repositories_stars ON repositories (stars)');
    }
}
