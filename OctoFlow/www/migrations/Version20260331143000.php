<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260331143000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add soft-delete columns for finance debt and installment plans';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE finance_installment_plan ADD deleted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_finance_installment_plan_owner_deleted_status ON finance_installment_plan (owner_id, deleted_at, status)');

        $this->addSql('ALTER TABLE finance_debt_plan ADD deleted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_finance_debt_plan_owner_deleted_status ON finance_debt_plan (owner_id, deleted_at, status)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_finance_installment_plan_owner_deleted_status');
        $this->addSql('ALTER TABLE finance_installment_plan DROP deleted_at');

        $this->addSql('DROP INDEX idx_finance_debt_plan_owner_deleted_status');
        $this->addSql('ALTER TABLE finance_debt_plan DROP deleted_at');
    }
}

