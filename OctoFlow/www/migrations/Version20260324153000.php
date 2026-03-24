<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260324153000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add label names collection to local tasks for GitHub synchronization tags';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE local_task ADD label_names JSON NOT NULL DEFAULT '[]'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE local_task DROP label_names');
    }
}
