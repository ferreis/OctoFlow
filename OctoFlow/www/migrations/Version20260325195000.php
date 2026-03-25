<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260325195000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create finance debt plan table with linkage to installment plans and entries';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE finance_debt_plan (id SERIAL NOT NULL, owner_id INT NOT NULL, category_id INT DEFAULT NULL, default_bank_account_id INT DEFAULT NULL, linked_installment_plan_id INT DEFAULT NULL, full_payment_entry_id INT DEFAULT NULL, title VARCHAR(180) NOT NULL, creditor_name VARCHAR(180) DEFAULT NULL, notes TEXT DEFAULT NULL, total_amount_brl NUMERIC(14, 2) NOT NULL, negotiated_amount_brl NUMERIC(14, 2) DEFAULT NULL, proposed_amount_brl NUMERIC(14, 2) DEFAULT NULL, selected_reference_amount_brl NUMERIC(14, 2) NOT NULL, selected_reference_type VARCHAR(20) NOT NULL, down_payment_brl NUMERIC(14, 2) NOT NULL, planned_total_amount_brl NUMERIC(14, 2) NOT NULL, monthly_income_brl NUMERIC(14, 2) NOT NULL, max_commitment_percent NUMERIC(5, 2) NOT NULL, max_recommended_payment_brl NUMERIC(14, 2) NOT NULL, settlement_mode VARCHAR(20) NOT NULL, selected_installments_count INT NOT NULL, selected_monthly_payment_brl NUMERIC(14, 2) NOT NULL, status VARCHAR(20) NOT NULL, negotiation_note TEXT DEFAULT NULL, proposal_note TEXT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))");
        $this->addSql('CREATE INDEX idx_finance_debt_plan_owner_status_created ON finance_debt_plan (owner_id, status, created_at)');
        $this->addSql('CREATE INDEX IDX_2D6D72267E3C61F9 ON finance_debt_plan (owner_id)');
        $this->addSql('CREATE INDEX IDX_2D6D722612469DE2 ON finance_debt_plan (category_id)');
        $this->addSql('CREATE INDEX IDX_2D6D722646EDB8B6 ON finance_debt_plan (default_bank_account_id)');
        $this->addSql('CREATE INDEX IDX_2D6D722628A7594D ON finance_debt_plan (linked_installment_plan_id)');
        $this->addSql('CREATE INDEX IDX_2D6D722628E6A0BE ON finance_debt_plan (full_payment_entry_id)');
        $this->addSql("ALTER TABLE finance_debt_plan ADD CONSTRAINT chk_finance_debt_plan_reference_type CHECK (selected_reference_type IN ('NEGOTIATED', 'PROPOSED', 'FULL'))");
        $this->addSql("ALTER TABLE finance_debt_plan ADD CONSTRAINT chk_finance_debt_plan_settlement_mode CHECK (settlement_mode IN ('INSTALLMENT', 'FULL'))");
        $this->addSql("ALTER TABLE finance_debt_plan ADD CONSTRAINT chk_finance_debt_plan_status CHECK (status IN ('PLANNED', 'ACTIVE', 'DONE', 'CANCELED'))");
        $this->addSql('ALTER TABLE finance_debt_plan ADD CONSTRAINT FK_2D6D72267E3C61F9 FOREIGN KEY (owner_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE finance_debt_plan ADD CONSTRAINT FK_2D6D722612469DE2 FOREIGN KEY (category_id) REFERENCES finance_category (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE finance_debt_plan ADD CONSTRAINT FK_2D6D722646EDB8B6 FOREIGN KEY (default_bank_account_id) REFERENCES finance_bank_account (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE finance_debt_plan ADD CONSTRAINT FK_2D6D722628A7594D FOREIGN KEY (linked_installment_plan_id) REFERENCES finance_installment_plan (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE finance_debt_plan ADD CONSTRAINT FK_2D6D722628E6A0BE FOREIGN KEY (full_payment_entry_id) REFERENCES finance_entry (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE finance_debt_plan');
    }
}
