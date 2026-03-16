<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260316173000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cria tabela de emails vinculados por usuario e define um email padrao editavel';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE user_email (id SERIAL NOT NULL, user_id INT NOT NULL, email VARCHAR(180) NOT NULL, providers JSON NOT NULL, is_primary BOOLEAN DEFAULT FALSE NOT NULL, is_verified BOOLEAN DEFAULT TRUE NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_USER_EMAIL_USER ON user_email (user_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_USER_EMAIL_EMAIL ON user_email (email)');
        $this->addSql('ALTER TABLE user_email ADD CONSTRAINT FK_USER_EMAIL_USER FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('INSERT INTO user_email (user_id, email, providers, is_primary, is_verified, created_at, updated_at) SELECT id, email, CASE WHEN google_subject IS NOT NULL THEN \'["google"]\'::json ELSE \'["system"]\'::json END, TRUE, TRUE, created_at, updated_at FROM app_user');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE user_email'); 
    }
}
