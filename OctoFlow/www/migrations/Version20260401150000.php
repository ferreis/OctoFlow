<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260401150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create finance currency rate cache table for daily API sync and manual rates';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE finance_currency_rate (
                id SERIAL NOT NULL,
                owner_id INT NOT NULL,
                currency_code VARCHAR(10) NOT NULL,
                currency_name VARCHAR(120) NOT NULL,
                quote_date DATE NOT NULL,
                quote_source_date DATE DEFAULT NULL,
                quote_datetime TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                rate_brl NUMERIC(14, 6) NOT NULL,
                buy_rate_brl NUMERIC(14, 6) DEFAULT NULL,
                sell_rate_brl NUMERIC(14, 6) DEFAULT NULL,
                source VARCHAR(20) NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
        SQL);
        $this->addSql('CREATE INDEX idx_finance_currency_rate_owner_date ON finance_currency_rate (owner_id, quote_date)');
        $this->addSql('CREATE UNIQUE INDEX uniq_finance_currency_rate_owner_code_date ON finance_currency_rate (owner_id, currency_code, quote_date)');
        $this->addSql('CREATE INDEX idx_finance_currency_rate_owner_code_source ON finance_currency_rate (owner_id, currency_code, source)');
        $this->addSql("ALTER TABLE finance_currency_rate ADD CONSTRAINT chk_finance_currency_rate_source CHECK (source IN ('API', 'MANUAL'))");
        $this->addSql('ALTER TABLE finance_currency_rate ADD CONSTRAINT FK_DF34D5A67E3C61F9 FOREIGN KEY (owner_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE finance_currency_rate');
    }
}
