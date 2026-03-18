<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260318150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adiciona preferencias globais de acessibilidade em ui_settings';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE ui_settings ADD color_vision_mode VARCHAR(32) DEFAULT 'none' NOT NULL");
        $this->addSql('ALTER TABLE ui_settings ADD color_vision_intensity INT DEFAULT 100 NOT NULL');
        $this->addSql('ALTER TABLE ui_settings ADD high_contrast_enabled BOOLEAN DEFAULT FALSE NOT NULL');
        $this->addSql("ALTER TABLE ui_settings ADD font_scale VARCHAR(32) DEFAULT 'default' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ui_settings DROP color_vision_mode');
        $this->addSql('ALTER TABLE ui_settings DROP color_vision_intensity');
        $this->addSql('ALTER TABLE ui_settings DROP high_contrast_enabled');
        $this->addSql('ALTER TABLE ui_settings DROP font_scale');
    }
}
