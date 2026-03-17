<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260318014500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove name_with_owner de github_repository e normaliza owner/name pela URL do GitHub';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE github_repository SET owner_login = regexp_replace(url, '^.*github\\.com[:/]+([^/]+)/([^/?#]+).*$','\\1'), name = regexp_replace(regexp_replace(url, '^.*github\\.com[:/]+([^/]+)/([^/?#]+).*$','\\2'), '\\.git$', '') WHERE url ~* 'github\\.com[:/]+[^/]+/[^/?#]+'");
        $this->addSql('DROP INDEX IF EXISTS uniq_github_repository_owner_key');
        $this->addSql('ALTER TABLE github_repository DROP COLUMN name_with_owner');
        $this->addSql('CREATE UNIQUE INDEX uniq_github_repository_owner_key ON github_repository (owner_id, owner_login, name)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS uniq_github_repository_owner_key');
        $this->addSql("ALTER TABLE github_repository ADD name_with_owner VARCHAR(191) NOT NULL DEFAULT ''");
        $this->addSql("UPDATE github_repository SET name_with_owner = CASE WHEN owner_login <> '' AND name <> '' THEN owner_login || '/' || name ELSE '' END");
        $this->addSql('CREATE UNIQUE INDEX uniq_github_repository_owner_key ON github_repository (owner_id, name_with_owner)');
    }
}
