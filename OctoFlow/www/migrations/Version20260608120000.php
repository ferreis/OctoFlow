<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260608120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove finance installment renegotiation table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS finance_negotiation');
    }

    public function down(Schema $schema): void
    {
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
    }
}
