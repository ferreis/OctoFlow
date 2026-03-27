<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260327190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adiciona location_hash em refresh_token para validação de contexto por localização';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE refresh_token ADD COLUMN location_hash VARCHAR(64) NOT NULL DEFAULT ''");
        $this->addSql('CREATE INDEX idx_refresh_token_location ON refresh_token (location_hash)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_refresh_token_location');
        $this->addSql('ALTER TABLE refresh_token DROP COLUMN location_hash');
    }
}
