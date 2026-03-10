<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260309120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adiciona campos de segurança avançada à tabela refresh_token: fingerprint, tokenFamily, userAgentHash e ipHash para detecção de reuso e contexto';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE refresh_token ADD COLUMN fingerprint_hash VARCHAR(64) NOT NULL DEFAULT ''");
        $this->addSql("ALTER TABLE refresh_token ADD COLUMN user_agent_hash VARCHAR(64) NOT NULL DEFAULT ''");
        $this->addSql("ALTER TABLE refresh_token ADD COLUMN ip_hash VARCHAR(64) NOT NULL DEFAULT ''");
        $this->addSql('ALTER TABLE refresh_token ADD COLUMN token_family_id VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE refresh_token ADD COLUMN parent_token_hash VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE refresh_token ADD COLUMN context_changed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE refresh_token ADD COLUMN reuse_detected_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_refresh_token_family ON refresh_token (token_family_id)');
        $this->addSql('CREATE INDEX idx_refresh_token_fingerprint ON refresh_token (fingerprint_hash)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_refresh_token_family');
        $this->addSql('DROP INDEX idx_refresh_token_fingerprint');
        $this->addSql('ALTER TABLE refresh_token DROP COLUMN fingerprint_hash');
        $this->addSql('ALTER TABLE refresh_token DROP COLUMN user_agent_hash');
        $this->addSql('ALTER TABLE refresh_token DROP COLUMN ip_hash');
        $this->addSql('ALTER TABLE refresh_token DROP COLUMN token_family_id');
        $this->addSql('ALTER TABLE refresh_token DROP COLUMN parent_token_hash');
        $this->addSql('ALTER TABLE refresh_token DROP COLUMN context_changed_at');
        $this->addSql('ALTER TABLE refresh_token DROP COLUMN reuse_detected_at');
    }
}
