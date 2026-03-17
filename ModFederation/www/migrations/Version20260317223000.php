<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260317223000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cria UISettings por usuario para preferencias visuais persistidas no backend';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE ui_settings (id SERIAL NOT NULL, user_id INT NOT NULL, theme_key VARCHAR(64) DEFAULT 'original' NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))");
        $this->addSql('CREATE UNIQUE INDEX UNIQ_9B3E1AE0A76ED395 ON ui_settings (user_id)');
        $this->addSql('ALTER TABLE ui_settings ADD CONSTRAINT FK_9B3E1AE0A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql("COMMENT ON COLUMN ui_settings.created_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql("COMMENT ON COLUMN ui_settings.updated_at IS '(DC2Type:datetime_immutable)'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ui_settings DROP CONSTRAINT FK_9B3E1AE0A76ED395');
        $this->addSql('DROP TABLE ui_settings');
    }
}
