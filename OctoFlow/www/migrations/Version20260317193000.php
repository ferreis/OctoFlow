<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260317193000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cria cache local de issues do GitHub por usuario e estado de sincronização com TTL';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE github_cached_issue (id SERIAL NOT NULL, owner_id INT NOT NULL, github_issue_id VARCHAR(191) NOT NULL, issue_number INT NOT NULL, repository_owner VARCHAR(191) NOT NULL, repository_name VARCHAR(191) NOT NULL, repository_key VARCHAR(191) NOT NULL, repository_url VARCHAR(500) DEFAULT NULL, title VARCHAR(500) NOT NULL, body TEXT NOT NULL, state VARCHAR(16) NOT NULL, url VARCHAR(500) NOT NULL, author_login VARCHAR(191) DEFAULT NULL, viewer_can_update BOOLEAN DEFAULT FALSE NOT NULL, viewer_can_close BOOLEAN DEFAULT FALSE NOT NULL, viewer_can_reopen BOOLEAN DEFAULT FALSE NOT NULL, assigned_to_viewer BOOLEAN DEFAULT FALSE NOT NULL, active BOOLEAN DEFAULT TRUE NOT NULL, assignees JSON NOT NULL, labels JSON NOT NULL, github_created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, github_updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, cached_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))");
        $this->addSql('CREATE UNIQUE INDEX uniq_github_cached_issue_owner_issue ON github_cached_issue (owner_id, github_issue_id)');
        $this->addSql('CREATE INDEX idx_github_cached_issue_owner_updated ON github_cached_issue (owner_id, active, github_updated_at)');
        $this->addSql('CREATE INDEX idx_github_cached_issue_owner_repo ON github_cached_issue (owner_id, repository_key)');
        $this->addSql('ALTER TABLE github_cached_issue ADD CONSTRAINT FK_GITHUB_CACHED_ISSUE_OWNER FOREIGN KEY (owner_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql("COMMENT ON COLUMN github_cached_issue.github_created_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql("COMMENT ON COLUMN github_cached_issue.github_updated_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql("COMMENT ON COLUMN github_cached_issue.cached_at IS '(DC2Type:datetime_immutable)'");

        $this->addSql("CREATE TABLE github_issue_sync_state (id SERIAL NOT NULL, owner_id INT NOT NULL, scope VARCHAR(32) NOT NULL, repository_key VARCHAR(191) NOT NULL, viewer_login VARCHAR(191) DEFAULT NULL, repository_catalog JSON NOT NULL, synced_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))");
        $this->addSql('CREATE UNIQUE INDEX uniq_github_issue_sync_state_owner_scope_repo ON github_issue_sync_state (owner_id, scope, repository_key)');
        $this->addSql('ALTER TABLE github_issue_sync_state ADD CONSTRAINT FK_GITHUB_ISSUE_SYNC_STATE_OWNER FOREIGN KEY (owner_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql("COMMENT ON COLUMN github_issue_sync_state.synced_at IS '(DC2Type:datetime_immutable)'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE github_issue_sync_state DROP CONSTRAINT FK_GITHUB_ISSUE_SYNC_STATE_OWNER');
        $this->addSql('ALTER TABLE github_cached_issue DROP CONSTRAINT FK_GITHUB_CACHED_ISSUE_OWNER');
        $this->addSql('DROP TABLE github_issue_sync_state');
        $this->addSql('DROP TABLE github_cached_issue');
    }
}
