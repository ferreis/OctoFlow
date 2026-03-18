<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260318203000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create local_task table for local tasks pending GitHub synchronization';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE local_task (id SERIAL NOT NULL, owner_id INT NOT NULL, template_key VARCHAR(64) DEFAULT NULL, title VARCHAR(500) NOT NULL, body TEXT NOT NULL, state VARCHAR(16) NOT NULL, sync_state VARCHAR(16) NOT NULL, sync_error TEXT DEFAULT NULL, repository_owner VARCHAR(191) DEFAULT NULL, repository_name VARCHAR(191) DEFAULT NULL, github_issue_id VARCHAR(191) DEFAULT NULL, github_issue_number INT DEFAULT NULL, github_issue_url VARCHAR(500) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, synced_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX idx_local_task_owner_sync_state ON local_task (owner_id, sync_state, updated_at)');
        $this->addSql('CREATE INDEX idx_local_task_owner_created ON local_task (owner_id, created_at)');
        $this->addSql('ALTER TABLE local_task ADD CONSTRAINT FK_LOCAL_TASK_OWNER FOREIGN KEY (owner_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE local_task DROP CONSTRAINT FK_LOCAL_TASK_OWNER');
        $this->addSql('DROP TABLE local_task');
    }
}
