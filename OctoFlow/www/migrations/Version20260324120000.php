<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260324120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add per-user custom theme palette for personalized UI theme';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ui_settings ADD custom_theme_palette JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ui_settings DROP custom_theme_palette');
    }
}
