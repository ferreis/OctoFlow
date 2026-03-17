<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260318001000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cria cadastro relacional de repositórios GitHub por usuário e remove colunas JSON legadas';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE github_repository (id SERIAL NOT NULL, owner_id INT NOT NULL, owner_login VARCHAR(191) NOT NULL, name VARCHAR(191) NOT NULL, name_with_owner VARCHAR(191) NOT NULL, url VARCHAR(500) NOT NULL, is_ignored BOOLEAN DEFAULT FALSE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX idx_github_repository_owner ON github_repository (owner_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_github_repository_owner_key ON github_repository (owner_id, name_with_owner)');
        $this->addSql('ALTER TABLE github_repository ADD CONSTRAINT FK_GITHUB_REPOSITORY_OWNER FOREIGN KEY (owner_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE github_issue_sync_state DROP COLUMN repository_catalog');
        $this->addSql('ALTER TABLE ui_settings DROP COLUMN ignored_repository_keys');
    }

    public function down(Schema $schema): void
    {
        $this->addSql("ALTER TABLE github_issue_sync_state ADD repository_catalog JSON DEFAULT '[]' NOT NULL");
        $this->addSql("ALTER TABLE ui_settings ADD ignored_repository_keys JSON DEFAULT '[]' NOT NULL");
        $this->addSql('ALTER TABLE github_repository DROP CONSTRAINT FK_GITHUB_REPOSITORY_OWNER');
        $this->addSql('DROP TABLE github_repository');
    }
}
