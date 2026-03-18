<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260318023000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove campos redundantes viewer_login e repository_key das tabelas de sincronização e cache de issues';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_github_cached_issue_owner_repo');
        $this->addSql('ALTER TABLE github_issue_sync_state DROP COLUMN viewer_login');
        $this->addSql('ALTER TABLE github_cached_issue DROP COLUMN repository_key');
        $this->addSql('CREATE INDEX idx_github_cached_issue_owner_repo ON github_cached_issue (owner_id, active, repository_owner, repository_name)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_github_cached_issue_owner_repo');
        $this->addSql('ALTER TABLE github_issue_sync_state ADD viewer_login VARCHAR(191) DEFAULT NULL');
        $this->addSql("ALTER TABLE github_cached_issue ADD repository_key VARCHAR(191) NOT NULL DEFAULT ''");
        $this->addSql("UPDATE github_cached_issue SET repository_key = CASE WHEN repository_owner <> '' AND repository_name <> '' THEN repository_owner || '/' || repository_name ELSE '' END");
        $this->addSql('CREATE INDEX idx_github_cached_issue_owner_repo ON github_cached_issue (owner_id, repository_key)');
    }
}
