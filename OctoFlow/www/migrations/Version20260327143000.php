<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260327143000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add password change by code fields to app_user';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user ADD password_change_code_hash VARCHAR(191) DEFAULT NULL');
        $this->addSql('ALTER TABLE app_user ADD password_change_code_expires_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE app_user ADD password_change_code_verified_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_app_user_password_change_code_expires ON app_user (password_change_code_expires_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_app_user_password_change_code_expires');
        $this->addSql('ALTER TABLE app_user DROP password_change_code_hash');
        $this->addSql('ALTER TABLE app_user DROP password_change_code_expires_at');
        $this->addSql('ALTER TABLE app_user DROP password_change_code_verified_at');
    }
}
