<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260325183000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add profile avatar metadata fields to app_user';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user ADD avatar_path VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE app_user ADD avatar_mime_type VARCHAR(50) DEFAULT NULL');
        $this->addSql('ALTER TABLE app_user ADD avatar_updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user DROP avatar_path');
        $this->addSql('ALTER TABLE app_user DROP avatar_mime_type');
        $this->addSql('ALTER TABLE app_user DROP avatar_updated_at');
    }
}
