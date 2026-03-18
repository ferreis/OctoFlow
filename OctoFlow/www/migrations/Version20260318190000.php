<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260318190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adiciona contas GitHub por usuario e vincula repositorios a contas';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE github_account (id SERIAL NOT NULL, owner_id INT NOT NULL, account_login VARCHAR(191) NOT NULL, token_encrypted TEXT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_7A0514EF7E3C61F9 ON github_account (owner_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_github_account_owner_login ON github_account (owner_id, account_login)');
        $this->addSql('ALTER TABLE github_account ADD CONSTRAINT FK_7A0514EF7E3C61F9 FOREIGN KEY (owner_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql('ALTER TABLE github_repository ADD account_id INT DEFAULT NULL');
        $this->addSql('CREATE INDEX IDX_BEF628A79B6B5FBA ON github_repository (account_id)');

        $this->addSql(<<<'SQL'
            INSERT INTO github_account (owner_id, account_login, token_encrypted, created_at, updated_at)
            SELECT
                u.id,
                COALESCE(NULLIF(TRIM(u.github_repository_owner), ''), repo.owner_login, 'github'),
                u.github_token_encrypted,
                NOW(),
                NOW()
            FROM app_user u
            LEFT JOIN (
                SELECT owner_id, MIN(owner_login) AS owner_login
                FROM github_repository
                GROUP BY owner_id
            ) repo ON repo.owner_id = u.id
            WHERE u.github_token_encrypted IS NOT NULL
               OR NULLIF(TRIM(u.github_repository_owner), '') IS NOT NULL
               OR repo.owner_login IS NOT NULL
        SQL);

        $this->addSql(<<<'SQL'
            UPDATE github_repository repository
            SET account_id = account.id
            FROM github_account account
            WHERE repository.owner_id = account.owner_id
              AND repository.account_id IS NULL
        SQL);

        $this->addSql('ALTER TABLE github_repository ALTER COLUMN account_id SET NOT NULL');
        $this->addSql('ALTER TABLE github_repository ADD CONSTRAINT FK_BEF628A79B6B5FBA FOREIGN KEY (account_id) REFERENCES github_account (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE github_repository DROP CONSTRAINT FK_BEF628A79B6B5FBA');
        $this->addSql('DROP INDEX IDX_BEF628A79B6B5FBA');
        $this->addSql('ALTER TABLE github_repository DROP account_id');

        $this->addSql('ALTER TABLE github_account DROP CONSTRAINT FK_7A0514EF7E3C61F9');
        $this->addSql('DROP TABLE github_account');
    }
}
