<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260324174000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add layout density mode and scale preferences to ui settings';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE ui_settings ADD layout_density_mode VARCHAR(32) DEFAULT 'comfortable' NOT NULL");
        $this->addSql('ALTER TABLE ui_settings ADD layout_density_scale INT DEFAULT 100 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ui_settings DROP layout_density_mode');
        $this->addSql('ALTER TABLE ui_settings DROP layout_density_scale');
    }
}
