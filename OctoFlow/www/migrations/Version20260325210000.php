<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260325210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add branch and account number fields to finance bank accounts';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE finance_bank_account ADD branch VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE finance_bank_account ADD account_number VARCHAR(30) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE finance_bank_account DROP branch');
        $this->addSql('ALTER TABLE finance_bank_account DROP account_number');
    }
}
