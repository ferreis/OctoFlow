<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260327110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add soft delete column to finance entries';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE finance_entry ADD deleted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_finance_entry_owner_deleted_due ON finance_entry (owner_id, deleted_at, due_date)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_finance_entry_owner_deleted_due');
        $this->addSql('ALTER TABLE finance_entry DROP deleted_at');
    }
}

