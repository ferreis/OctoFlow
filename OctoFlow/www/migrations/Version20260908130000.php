<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260908130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Synchronize installment and debt plan lifecycle statuses when linked finance entries are settled';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE OR REPLACE FUNCTION octoflow_sync_finance_plan_statuses_from_entry()
            RETURNS TRIGGER AS $$
            DECLARE
                linked_plan_id INT;
                live_items_count INT := 0;
                settled_items_count INT := 0;
                open_items_count INT := 0;
                next_plan_status VARCHAR(20) := 'ACTIVE';
            BEGIN
                SELECT item.plan_id
                  INTO linked_plan_id
                  FROM finance_installment_item item
                 WHERE item.entry_id = NEW.id
                 LIMIT 1;

                IF linked_plan_id IS NOT NULL THEN
                    SELECT
                        COUNT(*) FILTER (WHERE entry.deleted_at IS NULL),
                        COUNT(*) FILTER (
                            WHERE entry.deleted_at IS NULL
                              AND entry.remaining_amount_brl <= 0
                              AND entry.status IN ('PAID', 'RECEIVED')
                        ),
                        COUNT(*) FILTER (
                            WHERE entry.deleted_at IS NULL
                              AND entry.remaining_amount_brl > 0
                              AND entry.status NOT IN ('CANCELED', 'NEGOTIATED')
                        )
                      INTO live_items_count, settled_items_count, open_items_count
                      FROM finance_installment_item item
                      INNER JOIN finance_entry entry ON entry.id = item.entry_id
                     WHERE item.plan_id = linked_plan_id;

                    IF live_items_count > 0
                       AND settled_items_count = live_items_count
                       AND open_items_count = 0 THEN
                        next_plan_status := 'PAID';
                    ELSE
                        next_plan_status := 'ACTIVE';
                    END IF;

                    UPDATE finance_installment_plan
                       SET status = next_plan_status,
                           updated_at = NOW()
                     WHERE id = linked_plan_id
                       AND owner_id = NEW.owner_id
                       AND deleted_at IS NULL
                       AND status <> 'CANCELED'
                       AND status IS DISTINCT FROM next_plan_status;
                END IF;

                UPDATE finance_debt_plan debt_plan
                   SET status = CASE
                        WHEN debt_plan.full_payment_entry_id = NEW.id THEN
                            CASE
                                WHEN NEW.deleted_at IS NULL
                                 AND NEW.remaining_amount_brl <= 0
                                 AND NEW.status IN ('PAID', 'RECEIVED')
                                    THEN 'PAID'
                                ELSE 'ACTIVE'
                            END
                        WHEN linked_plan_id IS NOT NULL
                         AND debt_plan.linked_installment_plan_id = linked_plan_id THEN
                            CASE
                                WHEN EXISTS (
                                    SELECT 1
                                      FROM finance_installment_plan installment_plan
                                     WHERE installment_plan.id = linked_plan_id
                                       AND installment_plan.owner_id = NEW.owner_id
                                       AND installment_plan.deleted_at IS NULL
                                       AND installment_plan.status = 'PAID'
                                ) THEN 'PAID'
                                ELSE 'ACTIVE'
                            END
                        ELSE debt_plan.status
                    END,
                       updated_at = NOW()
                 WHERE debt_plan.owner_id = NEW.owner_id
                   AND debt_plan.deleted_at IS NULL
                   AND debt_plan.status <> 'CANCELED'
                   AND (
                        debt_plan.full_payment_entry_id = NEW.id
                        OR (
                            linked_plan_id IS NOT NULL
                            AND debt_plan.linked_installment_plan_id = linked_plan_id
                        )
                   );

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TRIGGER trg_finance_entry_sync_plan_statuses
            AFTER UPDATE OF remaining_amount_brl, status, deleted_at ON finance_entry
            FOR EACH ROW
            EXECUTE FUNCTION octoflow_sync_finance_plan_statuses_from_entry()
        SQL);

        // Corrige parcelamentos antigos que ficaram ACTIVE mesmo sem saldo em aberto.
        $this->addSql(<<<'SQL'
            UPDATE finance_installment_plan plan
               SET status = CASE
                    WHEN EXISTS (
                        SELECT 1
                          FROM finance_installment_item item
                          INNER JOIN finance_entry entry ON entry.id = item.entry_id
                         WHERE item.plan_id = plan.id
                           AND entry.deleted_at IS NULL
                    )
                    AND NOT EXISTS (
                        SELECT 1
                          FROM finance_installment_item item
                          INNER JOIN finance_entry entry ON entry.id = item.entry_id
                         WHERE item.plan_id = plan.id
                           AND entry.deleted_at IS NULL
                           AND NOT (
                               entry.remaining_amount_brl <= 0
                               AND entry.status IN ('PAID', 'RECEIVED')
                           )
                    ) THEN 'PAID'
                    ELSE 'ACTIVE'
                END,
                   updated_at = NOW()
             WHERE plan.deleted_at IS NULL
               AND plan.status <> 'CANCELED'
        SQL);

        // Propaga o estado corrigido para planos de dívida à vista ou parcelados.
        $this->addSql(<<<'SQL'
            UPDATE finance_debt_plan debt_plan
               SET status = CASE
                    WHEN debt_plan.full_payment_entry_id IS NOT NULL
                     AND EXISTS (
                        SELECT 1
                          FROM finance_entry entry
                         WHERE entry.id = debt_plan.full_payment_entry_id
                           AND entry.owner_id = debt_plan.owner_id
                           AND entry.deleted_at IS NULL
                           AND entry.remaining_amount_brl <= 0
                           AND entry.status IN ('PAID', 'RECEIVED')
                    ) THEN 'PAID'
                    WHEN debt_plan.linked_installment_plan_id IS NOT NULL
                     AND EXISTS (
                        SELECT 1
                          FROM finance_installment_plan installment_plan
                         WHERE installment_plan.id = debt_plan.linked_installment_plan_id
                           AND installment_plan.owner_id = debt_plan.owner_id
                           AND installment_plan.deleted_at IS NULL
                           AND installment_plan.status = 'PAID'
                    ) THEN 'PAID'
                    ELSE 'ACTIVE'
                END,
                   updated_at = NOW()
             WHERE debt_plan.deleted_at IS NULL
               AND debt_plan.status <> 'CANCELED'
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TRIGGER IF EXISTS trg_finance_entry_sync_plan_statuses ON finance_entry');
        $this->addSql('DROP FUNCTION IF EXISTS octoflow_sync_finance_plan_statuses_from_entry()');
    }
}
