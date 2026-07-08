<?php

namespace App\Tests\Unit\Finance;

use App\Entity\User;
use App\Finance\FinanceDashboardService;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

final class FinanceDashboardServiceTest extends TestCase
{
    private Connection $connection;
    private FinanceDashboardService $service;
    private User $user;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(Connection::class);
        $this->service = new FinanceDashboardService($this->connection);
        $this->user = (new User())
            ->setEmail('dashboard-test@example.com')
            ->setPassword('not-used')
            ->setRoles(['ROLE_FINANCE_READ']);
        $this->user->setId(42);
    }

    public function testSummaryReturnsAggregatedData(): void
    {
        $this->connection->expects($this->exactly(2))
            ->method('fetchAssociative')
            ->willReturnCallback(fn(string $sql, array $params): array => match (true) {
                str_contains($sql, 'finance_entry') => [
                    'expected_income_brl' => 10000.00,
                    'expected_expense_brl' => 7000.00,
                    'realized_income_brl' => 5000.00,
                    'realized_expense_brl' => 4000.00,
                    'remaining_total_brl' => 6000.00,
                    'overdue_entries_count' => 2,
                ],
                default => ['expected_income_brl' => 0],
            });

        $this->connection->expects($this->once())
            ->method('fetchOne')
            ->with(
                $this->callback(fn(string $sql): bool => str_contains($sql, 'finance_bank_account')),
                ['ownerId' => 42],
            )
            ->willReturn('15000.00');

        $result = $this->service->summary($this->user);

        $this->assertSame(10000.0, $result['expectedIncomeBrl']);
        $this->assertSame(7000.0, $result['expectedExpenseBrl']);
        $this->assertSame(5000.0, $result['realizedIncomeBrl']);
        $this->assertSame(4000.0, $result['realizedExpenseBrl']);
        $this->assertSame(3000.0, $result['expectedNetBrl']);
        $this->assertSame(1000.0, $result['realizedNetBrl']);
        $this->assertSame(6000.0, $result['remainingTotalBrl']);
        $this->assertSame(2, $result['overdueEntriesCount']);
        $this->assertSame(15000.0, $result['accountsBalanceBrl']);
    }

    public function testSummaryWithFiltersAppliesConstraints(): void
    {
        $this->connection->expects($this->any())
            ->method('fetchAssociative')
            ->with(
                $this->callback(fn(string $sql): bool => str_contains($sql, 'finance_entry')),
                $this->callback(function (array $params): bool {
                    return $params['ownerId'] === 42 && isset($params['startDate']);
                }),
            )
            ->willReturn([
                'expected_income_brl' => 5000.00,
                'expected_expense_brl' => 3000.00,
                'realized_income_brl' => 2000.00,
                'realized_expense_brl' => 1500.00,
                'remaining_total_brl' => 4500.00,
                'overdue_entries_count' => 0,
            ]);

        $this->connection->expects($this->once())
            ->method('fetchOne')
            ->willReturn('0');

        $result = $this->service->summary($this->user, [
            'startDate' => '2026-07-01',
            'endDate' => '2026-07-31',
        ]);

        $this->assertSame(5000.0, $result['expectedIncomeBrl']);
        $this->assertSame(3000.0, $result['expectedExpenseBrl']);
        $this->assertSame(2000.0, $result['expectedNetBrl']);
    }

    public function testCashflowReturnsMonthlySeries(): void
    {
        $this->connection->expects($this->once())
            ->method('fetchAllAssociative')
            ->with(
                $this->callback(fn(string $sql): bool => str_contains($sql, 'finance_entry')),
                $this->callback(fn(array $params): bool => $params['ownerId'] === 42),
            )
            ->willReturn([
                [
                    'competenceMonth' => '2026-07-01',
                    'expectedIncomeBrl' => 10000.00,
                    'expectedExpenseBrl' => 7000.00,
                    'realizedIncomeBrl' => 5000.00,
                    'realizedExpenseBrl' => 4000.00,
                ],
                [
                    'competenceMonth' => '2026-06-01',
                    'expectedIncomeBrl' => 9000.00,
                    'expectedExpenseBrl' => 6500.00,
                    'realizedIncomeBrl' => 4500.00,
                    'realizedExpenseBrl' => 4000.00,
                ],
            ]);

        $result = $this->service->cashflow($this->user);

        $this->assertCount(2, $result['items']);
        $this->assertSame('2026-07-01', $result['items'][0]['competenceMonth']);
        $this->assertSame(10000.0, $result['items'][0]['expectedIncomeBrl']);
    }
}
