<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260526180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Expand finance entry status constraints by direction';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE finance_entry DROP CONSTRAINT chk_finance_entry_status_by_direction');
        $this->addSql("ALTER TABLE finance_entry ADD CONSTRAINT chk_finance_entry_status_by_direction CHECK ((direction = 'PAYABLE' AND status IN ('PENDING', 'FORECAST', 'PAID', 'PARTIAL', 'OVERDUE', 'SCHEDULED', 'CANCELED', 'NEGOTIATED')) OR (direction = 'RECEIVABLE' AND status IN ('PENDING', 'FORECAST', 'RECEIVED', 'PARTIAL', 'OVERDUE', 'SCHEDULED', 'CANCELED', 'NEGOTIATED')))");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE finance_entry DROP CONSTRAINT chk_finance_entry_status_by_direction');
        $this->addSql("ALTER TABLE finance_entry ADD CONSTRAINT chk_finance_entry_status_by_direction CHECK ((direction = 'PAYABLE' AND status IN ('PENDING', 'PAID', 'PARTIAL', 'OVERDUE', 'SCHEDULED', 'CANCELED', 'NEGOTIATED')) OR (direction = 'RECEIVABLE' AND status IN ('FORECAST', 'RECEIVED', 'PARTIAL', 'OVERDUE', 'CANCELED')))");
    }
}
