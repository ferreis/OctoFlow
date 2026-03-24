<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260324161000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add local task history entries to persist tag changes';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE local_task ADD history_entries JSON NOT NULL DEFAULT '[]'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE local_task DROP history_entries');
    }
}
