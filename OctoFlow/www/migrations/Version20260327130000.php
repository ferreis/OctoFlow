<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260327130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add password setup flow fields for Google-created users';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user ADD password_login_enabled BOOLEAN DEFAULT true NOT NULL');
        $this->addSql('ALTER TABLE app_user ADD google_password_setup_code_hash VARCHAR(191) DEFAULT NULL');
        $this->addSql('ALTER TABLE app_user ADD google_password_setup_code_expires_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE app_user ADD google_password_setup_verified_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_app_user_google_password_setup_expires ON app_user (google_password_setup_code_expires_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_app_user_google_password_setup_expires');
        $this->addSql('ALTER TABLE app_user DROP password_login_enabled');
        $this->addSql('ALTER TABLE app_user DROP google_password_setup_code_hash');
        $this->addSql('ALTER TABLE app_user DROP google_password_setup_code_expires_at');
        $this->addSql('ALTER TABLE app_user DROP google_password_setup_verified_at');
    }
}
