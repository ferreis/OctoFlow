<?php

namespace App\Tests\Unit\Finance;

use App\Finance\FinanceCatalogService;
use App\Finance\FinanceEntryService;
use App\Finance\FinanceRecurringService;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

final class FinanceEntryServiceTest extends TestCase
{
    public function testReceiptCreditsBankAccountWithNegativeBalance(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())
            ->method('fetchAssociative')
            ->willReturn([
                'id' => 2,
                'currentBalanceBrl' => -500.00,
            ]);
        $connection->expects($this->once())
            ->method('update')
            ->with(
                'finance_bank_account',
                $this->callback(function (array $updatedFields): bool {
                    $this->assertSame(4344.33, $updatedFields['current_balance_brl']);

                    return true;
                }),
                ['id' => 2, 'owner_id' => 5],
            );
        $connection->expects($this->once())
            ->method('insert')
            ->with(
                'finance_bank_account_ledger',
                $this->callback(function (array $ledgerEntry): bool {
                    $this->assertSame('CREDIT', $ledgerEntry['movement_type']);
                    $this->assertSame(4844.33, $ledgerEntry['amount_brl']);
                    $this->assertSame(4344.33, $ledgerEntry['balance_after_brl']);

                    return true;
                }),
            );

        $this->applyBankAccountBalanceChange($connection, 'RECEIVABLE', 4844.33);
    }

    public function testPaymentDebitsBankAccountBelowZero(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())
            ->method('fetchAssociative')
            ->willReturn([
                'id' => 2,
                'currentBalanceBrl' => -500.00,
            ]);
        $connection->expects($this->once())
            ->method('update')
            ->with(
                'finance_bank_account',
                $this->callback(function (array $updatedFields): bool {
                    $this->assertSame(-600.00, $updatedFields['current_balance_brl']);

                    return true;
                }),
                ['id' => 2, 'owner_id' => 5],
            );
        $connection->expects($this->once())
            ->method('insert')
            ->with(
                'finance_bank_account_ledger',
                $this->callback(function (array $ledgerEntry): bool {
                    $this->assertSame('DEBIT', $ledgerEntry['movement_type']);
                    $this->assertSame(100.00, $ledgerEntry['amount_brl']);
                    $this->assertSame(-600.00, $ledgerEntry['balance_after_brl']);

                    return true;
                }),
            );

        $this->applyBankAccountBalanceChange($connection, 'PAYABLE', 100.00);
    }

    public function testRefreshOverdueStatusesIncludesForecastReceivables(): void
    {
        $connection = $this->createMock(Connection::class);
        $service = new FinanceEntryService(
            $connection,
            new FinanceCatalogService($connection),
            new FinanceRecurringService($connection),
        );

        $connection->expects($this->once())
            ->method('fetchAllAssociative')
            ->with(
                $this->callback(function (string $sql): bool {
                    $this->assertStringContainsString('due_date < :today', $sql);
                    $this->assertStringNotContainsString("'FORECAST'", $sql);

                    return true;
                }),
                $this->callback(function (array $parameters): bool {
                    $this->assertArrayHasKey('today', $parameters);

                    return true;
                }),
            )
            ->willReturn([
                [
                    'id' => 10,
                    'owner_id' => 5,
                    'status' => 'FORECAST',
                ],
            ]);

        $connection->expects($this->once())
            ->method('update')
            ->with(
                'finance_entry',
                $this->callback(function (array $data): bool {
                    $this->assertSame('OVERDUE', $data['status']);
                    $this->assertArrayHasKey('updated_at', $data);

                    return true;
                }),
                [
                    'id' => 10,
                    'owner_id' => 5,
                ],
            )
            ->willReturn(1);

        $connection->expects($this->once())
            ->method('insert')
            ->with(
                'finance_entry_status_history',
                $this->callback(function (array $data): bool {
                    $this->assertSame(5, $data['owner_id']);
                    $this->assertSame(10, $data['entry_id']);
                    $this->assertSame('FORECAST', $data['from_status']);
                    $this->assertSame('OVERDUE', $data['to_status']);
                    $this->assertSame('AUTO_OVERDUE', $data['reason_code']);
                    $this->assertArrayHasKey('changed_at', $data);

                    return true;
                }),
            );

        $this->assertSame(1, $service->refreshOverdueStatusesForAllUsers());
    }

    private function applyBankAccountBalanceChange(Connection $connection, string $entryDirection, float $amountBrl): void
    {
        $service = new FinanceEntryService(
            $connection,
            new FinanceCatalogService($connection),
            new FinanceRecurringService($connection),
        );
        $balanceChangeMethod = new \ReflectionMethod(FinanceEntryService::class, 'applyBankAccountBalanceChange');
        $balanceChangeMethod->setAccessible(true);
        $balanceChangeMethod->invoke(
            $service,
            5,
            2,
            9,
            10,
            $entryDirection,
            $amountBrl,
            new \DateTimeImmutable('2026-07-14 10:00:00'),
            false,
        );
    }
}
