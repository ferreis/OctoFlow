<?php

namespace App\Tests\Unit\Finance;

use PHPUnit\Framework\TestCase;

final class FinancePlanSettlementStatusMigrationTest extends TestCase
{
    public function testMigrationSynchronizesInstallmentAndDebtPlanStatuses(): void
    {
        $migrationPath = dirname(__DIR__, 3) . '/migrations/Version20260908130000.php';
        $migrationSource = file_get_contents($migrationPath);

        $this->assertIsString($migrationSource);
        $this->assertStringContainsString('trg_finance_entry_sync_plan_statuses', $migrationSource);
        $this->assertStringContainsString("next_plan_status := 'PAID'", $migrationSource);
        $this->assertStringContainsString('debt_plan.linked_installment_plan_id = linked_plan_id', $migrationSource);
        $this->assertStringContainsString('debt_plan.full_payment_entry_id = NEW.id', $migrationSource);
        $this->assertStringContainsString("entry.status IN ('PAID', 'RECEIVED')", $migrationSource);
    }

    public function testMigrationRepairsExistingOpenPlansWithoutReopeningCanceledPlans(): void
    {
        $migrationPath = dirname(__DIR__, 3) . '/migrations/Version20260908130000.php';
        $migrationSource = file_get_contents($migrationPath);

        $this->assertIsString($migrationSource);
        $this->assertStringContainsString('UPDATE finance_installment_plan plan', $migrationSource);
        $this->assertStringContainsString('UPDATE finance_debt_plan debt_plan', $migrationSource);
        $this->assertStringContainsString("plan.status <> 'CANCELED'", $migrationSource);
        $this->assertStringContainsString("debt_plan.status <> 'CANCELED'", $migrationSource);
    }
}
