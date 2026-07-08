<?php

namespace App\Tests\Unit\Finance;

use App\Entity\User;
use App\Finance\FinanceCatalogService;
use App\Finance\FinanceEntryService;
use App\Finance\FinanceInstallmentService;
use App\Finance\FinanceRecurringService;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

final class FinanceRequiredCategoryTest extends TestCase
{
    public function testCreateEntryRequiresCategory(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->never())->method('insert');

        $service = $this->buildEntryService($connection);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('A categoria e obrigatoria.');

        $service->createEntry($this->buildUserWithId(15), [
            'direction' => 'PAYABLE',
            'title' => 'Fornecedor',
            'dueDate' => '2026-07-10',
            'expectedAmountBrl' => 100,
        ]);
    }

    public function testCreateEntryRejectsInactiveCategory(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())
            ->method('fetchAssociative')
            ->with(
                $this->callback(static fn (string $sql): bool => str_contains($sql, 'finance_category')),
                [
                    'ownerId' => 15,
                    'categoryId' => 20,
                ],
            )
            ->willReturn([
                'id' => 20,
                'is_active' => false,
            ]);
        $connection->expects($this->never())->method('insert');

        $service = $this->buildEntryService($connection);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Categorias inativas nao podem ser usadas em registros financeiros.');

        $service->createEntry($this->buildUserWithId(15), [
            'direction' => 'PAYABLE',
            'title' => 'Fornecedor',
            'dueDate' => '2026-07-10',
            'expectedAmountBrl' => 100,
            'categoryId' => 20,
        ]);
    }

    public function testCreateRecurringRuleRequiresCategory(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())
            ->method('fetchOne')
            ->with(
                $this->callback(static fn (string $sql): bool => str_contains($sql, 'finance_recurring_type')),
                [
                    'ownerId' => 15,
                    'recurringTypeId' => 7,
                ],
            )
            ->willReturn(7);
        $connection->expects($this->never())->method('insert');

        $service = new FinanceRecurringService($connection);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('category is required');

        $service->createRule($this->buildUserWithId(15), [
            'direction' => 'PAYABLE',
            'title' => 'Assinatura',
            'amountBrl' => 49.9,
            'frequency' => 'MONTHLY',
            'dayOfMonth' => 10,
            'startsAt' => '2026-07-01',
            'recurringTypeId' => 7,
        ]);
    }

    public function testCreateInstallmentPlanRequiresCategory(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())
            ->method('transactional')
            ->willReturnCallback(static fn (\Closure $callback): mixed => $callback());
        $connection->expects($this->never())->method('insert');

        $service = new FinanceInstallmentService($connection, $this->buildEntryService($connection));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('category is required');

        $service->createPlan($this->buildUserWithId(15), [
            'direction' => 'PAYABLE',
            'title' => 'Compra parcelada',
            'totalAmountBrl' => 300,
            'installmentsCount' => 3,
            'firstDueDate' => '2026-07-10',
        ]);
    }

    private function buildEntryService(Connection $connection): FinanceEntryService
    {
        return new FinanceEntryService(
            $connection,
            new FinanceCatalogService($connection),
            new FinanceRecurringService($connection),
        );
    }

    private function buildUserWithId(int $id): User
    {
        $user = (new User())->setEmail('finance-owner@example.com');
        $property = new \ReflectionProperty(User::class, 'id');
        $property->setAccessible(true);
        $property->setValue($user, $id);

        return $user;
    }
}
