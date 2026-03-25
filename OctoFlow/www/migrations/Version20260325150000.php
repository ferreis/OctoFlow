<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260325150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create finance domain schema with entries, recurrence, installments, investments, exports and open finance base';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE finance_category (id SERIAL NOT NULL, owner_id INT NOT NULL, name VARCHAR(120) NOT NULL, normalized_name VARCHAR(120) NOT NULL, kind VARCHAR(20) NOT NULL, is_system BOOLEAN DEFAULT false NOT NULL, is_active BOOLEAN DEFAULT true NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))");
        $this->addSql('CREATE INDEX idx_finance_category_owner_kind ON finance_category (owner_id, kind)');
        $this->addSql('CREATE UNIQUE INDEX uniq_finance_category_owner_name_kind ON finance_category (owner_id, normalized_name, kind)');
        $this->addSql('ALTER TABLE finance_category ADD CONSTRAINT FK_7409C9F47E3C61F9 FOREIGN KEY (owner_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql("CREATE TABLE finance_recurring_type (id SERIAL NOT NULL, owner_id INT NOT NULL, name VARCHAR(120) NOT NULL, normalized_name VARCHAR(120) NOT NULL, description TEXT DEFAULT NULL, is_system BOOLEAN DEFAULT false NOT NULL, is_active BOOLEAN DEFAULT true NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))");
        $this->addSql('CREATE INDEX idx_finance_recurring_type_owner ON finance_recurring_type (owner_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_finance_recurring_type_owner_name ON finance_recurring_type (owner_id, normalized_name)');
        $this->addSql('ALTER TABLE finance_recurring_type ADD CONSTRAINT FK_D4A9F8477E3C61F9 FOREIGN KEY (owner_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql("CREATE TABLE finance_bank_account (id SERIAL NOT NULL, owner_id INT NOT NULL, name VARCHAR(120) NOT NULL, bank_name VARCHAR(120) NOT NULL, account_type VARCHAR(40) NOT NULL, current_balance_brl NUMERIC(14, 2) NOT NULL, color_hex VARCHAR(20) DEFAULT NULL, icon_key VARCHAR(60) DEFAULT NULL, is_active BOOLEAN DEFAULT true NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))");
        $this->addSql('CREATE INDEX idx_finance_bank_account_owner ON finance_bank_account (owner_id, is_active)');
        $this->addSql('ALTER TABLE finance_bank_account ADD CONSTRAINT FK_6457A41E7E3C61F9 FOREIGN KEY (owner_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql("CREATE TABLE finance_recurring_rule (id SERIAL NOT NULL, owner_id INT NOT NULL, recurring_type_id INT NOT NULL, category_id INT DEFAULT NULL, default_bank_account_id INT DEFAULT NULL, direction VARCHAR(20) NOT NULL, title VARCHAR(180) NOT NULL, description TEXT DEFAULT NULL, amount_brl NUMERIC(14, 2) NOT NULL, frequency VARCHAR(20) NOT NULL, day_of_month SMALLINT DEFAULT NULL, starts_at DATE NOT NULL, ends_at DATE DEFAULT NULL, next_run_date DATE DEFAULT NULL, is_active BOOLEAN DEFAULT true NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))");
        $this->addSql('CREATE INDEX idx_finance_recurring_rule_owner ON finance_recurring_rule (owner_id, is_active, next_run_date)');
        $this->addSql('CREATE INDEX IDX_3ABDF4AF35BD6D3A ON finance_recurring_rule (recurring_type_id)');
        $this->addSql('CREATE INDEX IDX_3ABDF4AF12469DE2 ON finance_recurring_rule (category_id)');
        $this->addSql('CREATE INDEX IDX_3ABDF4AF46EDB8B6 ON finance_recurring_rule (default_bank_account_id)');
        $this->addSql("ALTER TABLE finance_recurring_rule ADD CONSTRAINT chk_finance_recurring_rule_direction CHECK (direction IN ('PAYABLE', 'RECEIVABLE'))");
        $this->addSql('ALTER TABLE finance_recurring_rule ADD CONSTRAINT FK_3ABDF4AF7E3C61F9 FOREIGN KEY (owner_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE finance_recurring_rule ADD CONSTRAINT FK_3ABDF4AF35BD6D3A FOREIGN KEY (recurring_type_id) REFERENCES finance_recurring_type (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE finance_recurring_rule ADD CONSTRAINT FK_3ABDF4AF12469DE2 FOREIGN KEY (category_id) REFERENCES finance_category (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE finance_recurring_rule ADD CONSTRAINT FK_3ABDF4AF46EDB8B6 FOREIGN KEY (default_bank_account_id) REFERENCES finance_bank_account (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql("CREATE TABLE finance_installment_plan (id SERIAL NOT NULL, owner_id INT NOT NULL, category_id INT DEFAULT NULL, default_bank_account_id INT DEFAULT NULL, direction VARCHAR(20) NOT NULL, title VARCHAR(180) NOT NULL, total_amount_brl NUMERIC(14, 2) NOT NULL, down_payment_brl NUMERIC(14, 2) NOT NULL, installment_amount_brl NUMERIC(14, 2) NOT NULL, installments_count INT NOT NULL, interest_amount_brl NUMERIC(14, 2) NOT NULL, discount_amount_brl NUMERIC(14, 2) NOT NULL, fine_amount_brl NUMERIC(14, 2) NOT NULL, first_due_date DATE NOT NULL, status VARCHAR(20) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))");
        $this->addSql('CREATE INDEX idx_finance_installment_plan_owner_status ON finance_installment_plan (owner_id, status)');
        $this->addSql('CREATE INDEX IDX_DAB65E9012469DE2 ON finance_installment_plan (category_id)');
        $this->addSql('CREATE INDEX IDX_DAB65E9046EDB8B6 ON finance_installment_plan (default_bank_account_id)');
        $this->addSql("ALTER TABLE finance_installment_plan ADD CONSTRAINT chk_finance_installment_plan_direction CHECK (direction IN ('PAYABLE', 'RECEIVABLE'))");
        $this->addSql('ALTER TABLE finance_installment_plan ADD CONSTRAINT FK_DAB65E907E3C61F9 FOREIGN KEY (owner_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE finance_installment_plan ADD CONSTRAINT FK_DAB65E9012469DE2 FOREIGN KEY (category_id) REFERENCES finance_category (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE finance_installment_plan ADD CONSTRAINT FK_DAB65E9046EDB8B6 FOREIGN KEY (default_bank_account_id) REFERENCES finance_bank_account (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql("CREATE TABLE finance_investment_simulation (id SERIAL NOT NULL, owner_id INT NOT NULL, investment_type VARCHAR(40) NOT NULL, label VARCHAR(180) NOT NULL, initial_amount_brl NUMERIC(14, 2) NOT NULL, monthly_contribution_brl NUMERIC(14, 2) NOT NULL, period_months INT NOT NULL, rate_input_type VARCHAR(20) NOT NULL, rate_value NUMERIC(14, 6) NOT NULL, effective_monthly_rate NUMERIC(14, 6) NOT NULL, total_invested_brl NUMERIC(14, 2) NOT NULL, total_yield_brl NUMERIC(14, 2) NOT NULL, final_amount_brl NUMERIC(14, 2) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))");
        $this->addSql('CREATE INDEX idx_finance_investment_simulation_owner ON finance_investment_simulation (owner_id, created_at)');
        $this->addSql('ALTER TABLE finance_investment_simulation ADD CONSTRAINT FK_17BB30757E3C61F9 FOREIGN KEY (owner_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql("CREATE TABLE finance_investment_plan (id SERIAL NOT NULL, owner_id INT NOT NULL, source_simulation_id INT DEFAULT NULL, default_bank_account_id INT DEFAULT NULL, category_id INT DEFAULT NULL, label VARCHAR(180) NOT NULL, investment_type VARCHAR(40) NOT NULL, start_date DATE NOT NULL, contribution_day SMALLINT NOT NULL, monthly_contribution_brl NUMERIC(14, 2) NOT NULL, effective_monthly_rate NUMERIC(14, 6) NOT NULL, generate_yield_entries BOOLEAN DEFAULT false NOT NULL, yield_mode VARCHAR(20) NOT NULL, status VARCHAR(20) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))");
        $this->addSql('CREATE INDEX idx_finance_investment_plan_owner_status ON finance_investment_plan (owner_id, status)');
        $this->addSql('CREATE INDEX IDX_2254892EF3547F47 ON finance_investment_plan (source_simulation_id)');
        $this->addSql('CREATE INDEX IDX_2254892E46EDB8B6 ON finance_investment_plan (default_bank_account_id)');
        $this->addSql('CREATE INDEX IDX_2254892E12469DE2 ON finance_investment_plan (category_id)');
        $this->addSql('ALTER TABLE finance_investment_plan ADD CONSTRAINT FK_2254892E7E3C61F9 FOREIGN KEY (owner_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE finance_investment_plan ADD CONSTRAINT FK_2254892EF3547F47 FOREIGN KEY (source_simulation_id) REFERENCES finance_investment_simulation (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE finance_investment_plan ADD CONSTRAINT FK_2254892E46EDB8B6 FOREIGN KEY (default_bank_account_id) REFERENCES finance_bank_account (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE finance_investment_plan ADD CONSTRAINT FK_2254892E12469DE2 FOREIGN KEY (category_id) REFERENCES finance_category (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql("CREATE TABLE finance_external_provider (id SERIAL NOT NULL, provider_code VARCHAR(80) NOT NULL, name VARCHAR(180) NOT NULL, is_active BOOLEAN DEFAULT true NOT NULL, PRIMARY KEY(id))");
        $this->addSql('CREATE UNIQUE INDEX uniq_finance_external_provider_code ON finance_external_provider (provider_code)');

        $this->addSql("CREATE TABLE finance_external_connection (id SERIAL NOT NULL, owner_id INT NOT NULL, provider_id INT NOT NULL, external_consent_id VARCHAR(191) DEFAULT NULL, status VARCHAR(20) NOT NULL, consent_expires_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, last_sync_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))");
        $this->addSql('CREATE INDEX idx_finance_external_connection_owner_status ON finance_external_connection (owner_id, status)');
        $this->addSql('CREATE INDEX IDX_B4502D5A7E3C61F9 ON finance_external_connection (owner_id)');
        $this->addSql('CREATE INDEX IDX_B4502D5AA53A8AA ON finance_external_connection (provider_id)');
        $this->addSql('ALTER TABLE finance_external_connection ADD CONSTRAINT FK_B4502D5A7E3C61F9 FOREIGN KEY (owner_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE finance_external_connection ADD CONSTRAINT FK_B4502D5AA53A8AA FOREIGN KEY (provider_id) REFERENCES finance_external_provider (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql("CREATE TABLE finance_external_account (id SERIAL NOT NULL, connection_id INT NOT NULL, external_account_id VARCHAR(191) NOT NULL, display_name VARCHAR(180) NOT NULL, bank_name VARCHAR(180) DEFAULT NULL, account_type VARCHAR(40) DEFAULT NULL, currency_code VARCHAR(10) NOT NULL, is_active BOOLEAN DEFAULT true NOT NULL, PRIMARY KEY(id))");
        $this->addSql('CREATE INDEX idx_finance_external_account_connection ON finance_external_account (connection_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_finance_external_account_connection_external ON finance_external_account (connection_id, external_account_id)');
        $this->addSql('ALTER TABLE finance_external_account ADD CONSTRAINT FK_AA0084EA9B164F47 FOREIGN KEY (connection_id) REFERENCES finance_external_connection (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql("CREATE TABLE finance_external_transaction (id SERIAL NOT NULL, external_account_id INT NOT NULL, external_transaction_id VARCHAR(191) NOT NULL, posted_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, amount NUMERIC(14, 2) NOT NULL, currency_code VARCHAR(10) NOT NULL, direction VARCHAR(20) NOT NULL, description TEXT DEFAULT NULL, raw_payload JSON NOT NULL, normalized_category VARCHAR(120) DEFAULT NULL, PRIMARY KEY(id))");
        $this->addSql('CREATE INDEX idx_finance_external_transaction_account_posted ON finance_external_transaction (external_account_id, posted_at)');
        $this->addSql('CREATE UNIQUE INDEX uniq_finance_external_transaction_account_external ON finance_external_transaction (external_account_id, external_transaction_id)');
        $this->addSql('ALTER TABLE finance_external_transaction ADD CONSTRAINT FK_BFA962BE5A4DFD74 FOREIGN KEY (external_account_id) REFERENCES finance_external_account (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql("CREATE TABLE finance_entry (id SERIAL NOT NULL, owner_id INT NOT NULL, category_id INT DEFAULT NULL, bank_account_id INT DEFAULT NULL, recurring_rule_id INT DEFAULT NULL, installment_item_id INT DEFAULT NULL, investment_plan_run_id INT DEFAULT NULL, external_transaction_id INT DEFAULT NULL, direction VARCHAR(20) NOT NULL, entry_type VARCHAR(40) NOT NULL, status VARCHAR(20) NOT NULL, title VARCHAR(180) NOT NULL, description TEXT DEFAULT NULL, due_date DATE DEFAULT NULL, competence_month DATE DEFAULT NULL, expected_amount_brl NUMERIC(14, 2) NOT NULL, settled_amount_brl NUMERIC(14, 2) NOT NULL, remaining_amount_brl NUMERIC(14, 2) NOT NULL, input_currency_code VARCHAR(10) DEFAULT 'BRL' NOT NULL, input_amount NUMERIC(14, 2) DEFAULT NULL, fx_rate_to_brl NUMERIC(14, 6) DEFAULT NULL, fx_rate_date DATE DEFAULT NULL, source_origin VARCHAR(20) NOT NULL, source_system VARCHAR(60) DEFAULT NULL, fully_settled_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))");
        $this->addSql('CREATE INDEX idx_finance_entry_owner_direction_status_due ON finance_entry (owner_id, direction, status, due_date)');
        $this->addSql('CREATE INDEX idx_finance_entry_owner_competence ON finance_entry (owner_id, competence_month)');
        $this->addSql('CREATE INDEX IDX_791EA9B12469DE2 ON finance_entry (category_id)');
        $this->addSql('CREATE INDEX IDX_791EA9B19A4DFD74 ON finance_entry (bank_account_id)');
        $this->addSql('CREATE INDEX IDX_791EA9B1450A3D5A ON finance_entry (recurring_rule_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_finance_entry_external_transaction ON finance_entry (external_transaction_id)');
        $this->addSql("ALTER TABLE finance_entry ADD CONSTRAINT chk_finance_entry_direction CHECK (direction IN ('PAYABLE', 'RECEIVABLE'))");
        $this->addSql("ALTER TABLE finance_entry ADD CONSTRAINT chk_finance_entry_status_by_direction CHECK ((direction = 'PAYABLE' AND status IN ('PENDING', 'PAID', 'PARTIAL', 'OVERDUE', 'SCHEDULED', 'CANCELED', 'NEGOTIATED')) OR (direction = 'RECEIVABLE' AND status IN ('FORECAST', 'RECEIVED', 'PARTIAL', 'OVERDUE', 'CANCELED'))) ");
        $this->addSql('ALTER TABLE finance_entry ADD CONSTRAINT FK_791EA9B17E3C61F9 FOREIGN KEY (owner_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE finance_entry ADD CONSTRAINT FK_791EA9B12469DE2 FOREIGN KEY (category_id) REFERENCES finance_category (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE finance_entry ADD CONSTRAINT FK_791EA9B19A4DFD74 FOREIGN KEY (bank_account_id) REFERENCES finance_bank_account (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE finance_entry ADD CONSTRAINT FK_791EA9B1450A3D5A FOREIGN KEY (recurring_rule_id) REFERENCES finance_recurring_rule (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE finance_entry ADD CONSTRAINT FK_791EA9B14917DAA2 FOREIGN KEY (external_transaction_id) REFERENCES finance_external_transaction (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql("CREATE TABLE finance_entry_settlement (id SERIAL NOT NULL, owner_id INT NOT NULL, entry_id INT NOT NULL, bank_account_id INT DEFAULT NULL, settlement_type VARCHAR(30) NOT NULL, amount_brl NUMERIC(14, 2) NOT NULL, settled_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, note TEXT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))");
        $this->addSql('CREATE INDEX idx_finance_entry_settlement_entry_settled ON finance_entry_settlement (entry_id, settled_at)');
        $this->addSql('CREATE INDEX IDX_D3C7AE917E3C61F9 ON finance_entry_settlement (owner_id)');
        $this->addSql('CREATE INDEX IDX_D3C7AE91BA364942 ON finance_entry_settlement (entry_id)');
        $this->addSql('CREATE INDEX IDX_D3C7AE919A4DFD74 ON finance_entry_settlement (bank_account_id)');
        $this->addSql('ALTER TABLE finance_entry_settlement ADD CONSTRAINT FK_D3C7AE917E3C61F9 FOREIGN KEY (owner_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE finance_entry_settlement ADD CONSTRAINT FK_D3C7AE91BA364942 FOREIGN KEY (entry_id) REFERENCES finance_entry (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE finance_entry_settlement ADD CONSTRAINT FK_D3C7AE919A4DFD74 FOREIGN KEY (bank_account_id) REFERENCES finance_bank_account (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql("CREATE TABLE finance_entry_status_history (id SERIAL NOT NULL, owner_id INT NOT NULL, entry_id INT NOT NULL, from_status VARCHAR(20) DEFAULT NULL, to_status VARCHAR(20) NOT NULL, reason_code VARCHAR(40) DEFAULT NULL, reason_text TEXT DEFAULT NULL, changed_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))");
        $this->addSql('CREATE INDEX idx_finance_entry_status_history_entry_changed ON finance_entry_status_history (entry_id, changed_at)');
        $this->addSql('CREATE INDEX IDX_DDC9C9A47E3C61F9 ON finance_entry_status_history (owner_id)');
        $this->addSql('CREATE INDEX IDX_DDC9C9A4BA364942 ON finance_entry_status_history (entry_id)');
        $this->addSql('ALTER TABLE finance_entry_status_history ADD CONSTRAINT FK_DDC9C9A47E3C61F9 FOREIGN KEY (owner_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE finance_entry_status_history ADD CONSTRAINT FK_DDC9C9A4BA364942 FOREIGN KEY (entry_id) REFERENCES finance_entry (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql("CREATE TABLE finance_bank_account_ledger (id SERIAL NOT NULL, owner_id INT NOT NULL, bank_account_id INT NOT NULL, entry_id INT DEFAULT NULL, settlement_id INT DEFAULT NULL, movement_type VARCHAR(30) NOT NULL, amount_brl NUMERIC(14, 2) NOT NULL, balance_after_brl NUMERIC(14, 2) NOT NULL, happened_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, source_type VARCHAR(40) NOT NULL, PRIMARY KEY(id))");
        $this->addSql('CREATE INDEX idx_finance_bank_account_ledger_account_happened ON finance_bank_account_ledger (bank_account_id, happened_at)');
        $this->addSql('CREATE INDEX IDX_1CFD028A7E3C61F9 ON finance_bank_account_ledger (owner_id)');
        $this->addSql('CREATE INDEX IDX_1CFD028A9A4DFD74 ON finance_bank_account_ledger (bank_account_id)');
        $this->addSql('CREATE INDEX IDX_1CFD028ABA364942 ON finance_bank_account_ledger (entry_id)');
        $this->addSql('CREATE INDEX IDX_1CFD028A5141C152 ON finance_bank_account_ledger (settlement_id)');
        $this->addSql('ALTER TABLE finance_bank_account_ledger ADD CONSTRAINT FK_1CFD028A7E3C61F9 FOREIGN KEY (owner_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE finance_bank_account_ledger ADD CONSTRAINT FK_1CFD028A9A4DFD74 FOREIGN KEY (bank_account_id) REFERENCES finance_bank_account (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE finance_bank_account_ledger ADD CONSTRAINT FK_1CFD028ABA364942 FOREIGN KEY (entry_id) REFERENCES finance_entry (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE finance_bank_account_ledger ADD CONSTRAINT FK_1CFD028A5141C152 FOREIGN KEY (settlement_id) REFERENCES finance_entry_settlement (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql("CREATE TABLE finance_recurring_rule_run (id SERIAL NOT NULL, recurring_rule_id INT NOT NULL, generated_entry_id INT NOT NULL, competence_month DATE NOT NULL, run_source VARCHAR(20) NOT NULL, generated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))");
        $this->addSql('CREATE INDEX idx_finance_recurring_rule_run_rule_competence ON finance_recurring_rule_run (recurring_rule_id, competence_month)');
        $this->addSql('CREATE UNIQUE INDEX uniq_finance_recurring_rule_run_rule_month ON finance_recurring_rule_run (recurring_rule_id, competence_month)');
        $this->addSql('CREATE INDEX IDX_9A44A9CB450A3D5A ON finance_recurring_rule_run (recurring_rule_id)');
        $this->addSql('CREATE INDEX IDX_9A44A9CBCD8D66D2 ON finance_recurring_rule_run (generated_entry_id)');
        $this->addSql('ALTER TABLE finance_recurring_rule_run ADD CONSTRAINT FK_9A44A9CB450A3D5A FOREIGN KEY (recurring_rule_id) REFERENCES finance_recurring_rule (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE finance_recurring_rule_run ADD CONSTRAINT FK_9A44A9CBCD8D66D2 FOREIGN KEY (generated_entry_id) REFERENCES finance_entry (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql("CREATE TABLE finance_installment_item (id SERIAL NOT NULL, plan_id INT NOT NULL, entry_id INT DEFAULT NULL, installment_number INT NOT NULL, due_date DATE NOT NULL, expected_amount_brl NUMERIC(14, 2) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))");
        $this->addSql('CREATE INDEX idx_finance_installment_item_plan ON finance_installment_item (plan_id, installment_number)');
        $this->addSql('CREATE UNIQUE INDEX uniq_finance_installment_item_plan_number ON finance_installment_item (plan_id, installment_number)');
        $this->addSql('CREATE UNIQUE INDEX uniq_finance_installment_item_entry ON finance_installment_item (entry_id)');
        $this->addSql('CREATE INDEX IDX_4B522DF06B2A97F ON finance_installment_item (plan_id)');
        $this->addSql('CREATE INDEX IDX_4B522DF0BA364942 ON finance_installment_item (entry_id)');
        $this->addSql('ALTER TABLE finance_installment_item ADD CONSTRAINT FK_4B522DF06B2A97F FOREIGN KEY (plan_id) REFERENCES finance_installment_plan (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE finance_installment_item ADD CONSTRAINT FK_4B522DF0BA364942 FOREIGN KEY (entry_id) REFERENCES finance_entry (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql('ALTER TABLE finance_entry ADD CONSTRAINT FK_791EA9B165649C34 FOREIGN KEY (installment_item_id) REFERENCES finance_installment_item (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql("CREATE TABLE finance_negotiation (id SERIAL NOT NULL, owner_id INT NOT NULL, original_entry_id INT DEFAULT NULL, original_plan_id INT DEFAULT NULL, new_plan_id INT NOT NULL, reason TEXT DEFAULT NULL, discount_amount_brl NUMERIC(14, 2) NOT NULL, fine_amount_brl NUMERIC(14, 2) NOT NULL, interest_amount_brl NUMERIC(14, 2) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))");
        $this->addSql('CREATE INDEX idx_finance_negotiation_owner ON finance_negotiation (owner_id, created_at)');
        $this->addSql('CREATE INDEX IDX_5103E5BF7E3C61F9 ON finance_negotiation (owner_id)');
        $this->addSql('CREATE INDEX IDX_5103E5BF260FD011 ON finance_negotiation (original_entry_id)');
        $this->addSql('CREATE INDEX IDX_5103E5BF2F74FD7 ON finance_negotiation (original_plan_id)');
        $this->addSql('CREATE INDEX IDX_5103E5BF75AE6FD3 ON finance_negotiation (new_plan_id)');
        $this->addSql('ALTER TABLE finance_negotiation ADD CONSTRAINT FK_5103E5BF7E3C61F9 FOREIGN KEY (owner_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE finance_negotiation ADD CONSTRAINT FK_5103E5BF260FD011 FOREIGN KEY (original_entry_id) REFERENCES finance_entry (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE finance_negotiation ADD CONSTRAINT FK_5103E5BF2F74FD7 FOREIGN KEY (original_plan_id) REFERENCES finance_installment_plan (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE finance_negotiation ADD CONSTRAINT FK_5103E5BF75AE6FD3 FOREIGN KEY (new_plan_id) REFERENCES finance_installment_plan (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql("CREATE TABLE finance_investment_simulation_point (id SERIAL NOT NULL, simulation_id INT NOT NULL, month_index INT NOT NULL, invested_amount_brl NUMERIC(14, 2) NOT NULL, yield_amount_brl NUMERIC(14, 2) NOT NULL, total_amount_brl NUMERIC(14, 2) NOT NULL, PRIMARY KEY(id))");
        $this->addSql('CREATE INDEX idx_finance_investment_simulation_point_simulation_month ON finance_investment_simulation_point (simulation_id, month_index)');
        $this->addSql('CREATE UNIQUE INDEX uniq_finance_investment_simulation_point_month ON finance_investment_simulation_point (simulation_id, month_index)');
        $this->addSql('CREATE INDEX IDX_5A45B012F3547F47 ON finance_investment_simulation_point (simulation_id)');
        $this->addSql('ALTER TABLE finance_investment_simulation_point ADD CONSTRAINT FK_5A45B012F3547F47 FOREIGN KEY (simulation_id) REFERENCES finance_investment_simulation (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql("CREATE TABLE finance_investment_plan_run (id SERIAL NOT NULL, investment_plan_id INT NOT NULL, contribution_entry_id INT DEFAULT NULL, yield_entry_id INT DEFAULT NULL, competence_month DATE NOT NULL, run_source VARCHAR(20) NOT NULL, generated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))");
        $this->addSql('CREATE INDEX idx_finance_investment_plan_run_plan_month ON finance_investment_plan_run (investment_plan_id, competence_month)');
        $this->addSql('CREATE UNIQUE INDEX uniq_finance_investment_plan_run_plan_month ON finance_investment_plan_run (investment_plan_id, competence_month)');
        $this->addSql('CREATE INDEX IDX_C3F7839BB9DBA464 ON finance_investment_plan_run (investment_plan_id)');
        $this->addSql('CREATE INDEX IDX_C3F7839B1AB5D907 ON finance_investment_plan_run (contribution_entry_id)');
        $this->addSql('CREATE INDEX IDX_C3F7839B35880C0A ON finance_investment_plan_run (yield_entry_id)');
        $this->addSql('ALTER TABLE finance_investment_plan_run ADD CONSTRAINT FK_C3F7839BB9DBA464 FOREIGN KEY (investment_plan_id) REFERENCES finance_investment_plan (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE finance_investment_plan_run ADD CONSTRAINT FK_C3F7839B1AB5D907 FOREIGN KEY (contribution_entry_id) REFERENCES finance_entry (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE finance_investment_plan_run ADD CONSTRAINT FK_C3F7839B35880C0A FOREIGN KEY (yield_entry_id) REFERENCES finance_entry (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql('ALTER TABLE finance_entry ADD CONSTRAINT FK_791EA9B1CE35E2D FOREIGN KEY (investment_plan_run_id) REFERENCES finance_investment_plan_run (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql("CREATE TABLE finance_export_job (id SERIAL NOT NULL, owner_id INT NOT NULL, export_type VARCHAR(40) NOT NULL, filters_json JSON NOT NULL, status VARCHAR(20) NOT NULL, file_name VARCHAR(191) DEFAULT NULL, file_path VARCHAR(500) DEFAULT NULL, requested_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, started_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, finished_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, expires_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, error_message TEXT DEFAULT NULL, PRIMARY KEY(id))");
        $this->addSql('CREATE INDEX idx_finance_export_job_owner_status_requested ON finance_export_job (owner_id, status, requested_at)');
        $this->addSql('CREATE INDEX IDX_5EAAB7767E3C61F9 ON finance_export_job (owner_id)');
        $this->addSql('ALTER TABLE finance_export_job ADD CONSTRAINT FK_5EAAB7767E3C61F9 FOREIGN KEY (owner_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql("CREATE TABLE finance_external_transaction_link (id SERIAL NOT NULL, external_transaction_id INT NOT NULL, entry_id INT NOT NULL, linked_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))");
        $this->addSql('CREATE UNIQUE INDEX uniq_finance_external_transaction_link_external ON finance_external_transaction_link (external_transaction_id)');
        $this->addSql('CREATE INDEX IDX_A5FA74B4917DAA2 ON finance_external_transaction_link (external_transaction_id)');
        $this->addSql('CREATE INDEX IDX_A5FA74B4BA364942 ON finance_external_transaction_link (entry_id)');
        $this->addSql('ALTER TABLE finance_external_transaction_link ADD CONSTRAINT FK_A5FA74B4917DAA2 FOREIGN KEY (external_transaction_id) REFERENCES finance_external_transaction (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE finance_external_transaction_link ADD CONSTRAINT FK_A5FA74B4BA364942 FOREIGN KEY (entry_id) REFERENCES finance_entry (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql(<<<'SQL'
            INSERT INTO finance_external_provider (provider_code, name, is_active)
            VALUES
                ('MANUAL_MOCK', 'Mock Manual Connector', true),
                ('OPEN_FINANCE_BR', 'Open Finance Brasil (future)', true)
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE finance_external_transaction_link');
        $this->addSql('DROP TABLE finance_export_job');
        $this->addSql('ALTER TABLE finance_entry DROP CONSTRAINT FK_791EA9B1CE35E2D');
        $this->addSql('DROP TABLE finance_investment_plan_run');
        $this->addSql('DROP TABLE finance_investment_simulation_point');
        $this->addSql('DROP TABLE finance_negotiation');
        $this->addSql('ALTER TABLE finance_entry DROP CONSTRAINT FK_791EA9B165649C34');
        $this->addSql('DROP TABLE finance_installment_item');
        $this->addSql('DROP TABLE finance_recurring_rule_run');
        $this->addSql('DROP TABLE finance_bank_account_ledger');
        $this->addSql('DROP TABLE finance_entry_status_history');
        $this->addSql('DROP TABLE finance_entry_settlement');
        $this->addSql('DROP TABLE finance_entry');
        $this->addSql('DROP TABLE finance_external_transaction');
        $this->addSql('DROP TABLE finance_external_account');
        $this->addSql('DROP TABLE finance_external_connection');
        $this->addSql('DROP TABLE finance_external_provider');
        $this->addSql('DROP TABLE finance_investment_plan');
        $this->addSql('DROP TABLE finance_investment_simulation');
        $this->addSql('DROP TABLE finance_installment_plan');
        $this->addSql('DROP TABLE finance_recurring_rule');
        $this->addSql('DROP TABLE finance_bank_account');
        $this->addSql('DROP TABLE finance_recurring_type');
        $this->addSql('DROP TABLE finance_category');
    }
}
