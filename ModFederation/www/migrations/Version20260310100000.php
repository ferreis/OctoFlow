<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260310100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adiciona vínculo opcional do usuário com o subject do Google OAuth';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user ADD COLUMN google_subject VARCHAR(191) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_APP_USER_GOOGLE_SUBJECT ON app_user (google_subject)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_APP_USER_GOOGLE_SUBJECT');
        $this->addSql('ALTER TABLE app_user DROP COLUMN google_subject');
    }
}
