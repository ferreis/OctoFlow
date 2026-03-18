<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260316143000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Armazena configuração do workspace GitHub por usuario no banco de dados';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user ADD COLUMN github_repository_owner VARCHAR(191) DEFAULT NULL');
        $this->addSql('ALTER TABLE app_user ADD COLUMN github_repository_name VARCHAR(191) DEFAULT NULL');
        $this->addSql('ALTER TABLE app_user ADD COLUMN github_token_encrypted TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user DROP COLUMN github_repository_owner');
        $this->addSql('ALTER TABLE app_user DROP COLUMN github_repository_name');
        $this->addSql('ALTER TABLE app_user DROP COLUMN github_token_encrypted');
    }
}
