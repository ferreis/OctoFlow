<?php

namespace App\Tests\Unit\Finance;

use App\Entity\User;
use App\Finance\FinanceInput;
use App\Finance\FinanceRecurringService;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

final class FinanceRecurringServiceTest extends TestCase
{
    private Connection $connection;
    private FinanceRecurringService $service;
    private User $user;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(Connection::class);
        $this->service = new FinanceRecurringService($this->connection);
        $this->user = (new User())
            ->setEmail('recurring-test@example.com')
            ->setPassword('not-used')
            ->setRoles(['ROLE_FINANCE_WRITE']);
        $this->user->setId(42);
    }

    public function testListRulesReturnsFormattedItems(): void
    {
        $this->connection->expects($this->once())
            ->method('fetchAllAssociative')
            ->with(
                $this->callback(fn(string $sql): bool => str_contains($sql, 'finance_recurring_rule')),
                ['ownerId' => 42],
            )
            ->willReturn([
                [
                    'id' => 1,
                    'direction' => 'PAYABLE',
                    'title' => 'Aluguel',
                    'amountBrl' => 1500.00,
                    'dayOfMonth' => 5,
                    'isActive' => 1,
                    'recurringTypeName' => 'Mensal',
                    'categoryName' => 'Moradia',
                ],
            ]);

        $result = $this->service->listRules($this->user);

        $this->assertArrayHasKey('items', $result);
        $this->assertCount(1, $result['items']);
        $this->assertSame('Aluguel', $result['items'][0]['title']);
    }

    public function testCreateRuleInsertsAndReturnsRule(): void
    {
        $this->connection->expects($this->once())
            ->method('fetchAllAssociative')
            ->with(
                $this->callback(fn(string $sql): bool => str_contains($sql, 'finance_category')),
                $this->callback(fn(array $params): bool => $params['ownerId'] === 42),
            )
            ->willReturn([['id' => 10, 'name' => 'Moradia', 'is_active' => 1]]);

        $this->connection->expects($this->once())
            ->method('fetchOne')
            ->with(
                $this->callback(fn(string $sql): bool => str_contains($sql, 'finance_recurring_type')),
                $this->callback(fn(array $params): bool => $params['ownerId'] === 42),
            )
            ->willReturn(5);

        $this->connection->expects($this->once())
            ->method('insert')
            ->with('finance_recurring_rule', $this->callback(function (array $data): bool {
                $this->assertSame(42, $data['owner_id']);
                $this->assertSame('PAYABLE', $data['direction']);
                $this->assertSame('Aluguel', $data['title']);
                $this->assertSame(1500.0, $data['amount_brl']);

                return true;
            }));

        $this->connection->expects($this->once())
            ->method('lastInsertId')
            ->willReturn('99');

        $this->connection->expects($this->once())
            ->method('fetchAssociative')
            ->with(
                $this->callback(fn(string $sql): bool => str_contains($sql, 'finance_recurring_rule')),
                ['ownerId' => 42, 'ruleId' => 99],
            )
            ->willReturn([
                'id' => 99,
                'direction' => 'PAYABLE',
                'title' => 'Aluguel',
                'amountBrl' => 1500.00,
                'dayOfMonth' => 5,
                'startsAt' => '2026-08-01',
                'isActive' => 1,
                'recurringTypeId' => 5,
                'recurringTypeName' => 'Mensal',
                'categoryId' => 10,
                'categoryName' => 'Moradia',
            ]);

        $result = $this->service->createRule($this->user, [
            'direction' => 'PAYABLE',
            'title' => 'Aluguel',
            'amountBrl' => 1500.00,
            'dayOfMonth' => 5,
            'startsAt' => '2026-08-01',
            'recurringTypeId' => 5,
            'categoryId' => 10,
        ]);

        $this->assertSame(99, $result['id']);
        $this->assertSame('Aluguel', $result['title']);
    }

    public function testCreateRuleThrowsOnEmptyTitle(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('recurring rule title is required');

        $this->service->createRule($this->user, [
            'title' => '',
            'amountBrl' => 100,
            'dayOfMonth' => 5,
            'startsAt' => '2026-08-01',
        ]);
    }

    public function testCreateRuleThrowsOnZeroAmount(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('amount must be greater than zero');

        $this->service->createRule($this->user, [
            'title' => 'Test',
            'amountBrl' => 0,
            'dayOfMonth' => 5,
            'startsAt' => '2026-08-01',
        ]);
    }
}
