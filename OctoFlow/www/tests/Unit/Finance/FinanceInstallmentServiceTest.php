<?php

namespace App\Tests\Unit\Finance;

use App\Entity\User;
use App\Finance\FinanceEntryService;
use App\Finance\FinanceInstallmentService;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

final class FinanceInstallmentServiceTest extends TestCase
{
    private Connection $connection;
    private FinanceEntryService $entryService;
    private FinanceInstallmentService $service;
    private User $user;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(Connection::class);
        $this->entryService = $this->createMock(FinanceEntryService::class);
        $this->service = new FinanceInstallmentService($this->connection, $this->entryService);
        $this->user = (new User())
            ->setEmail('installment-test@example.com')
            ->setPassword('not-used')
            ->setRoles(['ROLE_FINANCE_WRITE']);
        $this->user->setId(42);
    }

    public function testListPlansReturnsFormattedItems(): void
    {
        $this->connection->expects($this->once())
            ->method('fetchAllAssociative')
            ->with(
                $this->callback(fn(string $sql): bool => str_contains($sql, 'finance_installment_plan')),
                ['ownerId' => 42],
            )
            ->willReturn([
                [
                    'id' => 1,
                    'direction' => 'PAYABLE',
                    'title' => 'Notebook',
                    'totalAmountBrl' => 5000.00,
                    'installmentsCount' => 12,
                    'status' => 'ACTIVE',
                    'remainingAmountBrl' => 3000.00,
                    'itemsCount' => 12,
                    'paidItemsCount' => 4,
                    'categoryName' => 'Equipamentos',
                ],
            ]);

        $result = $this->service->listPlans($this->user);

        $this->assertArrayHasKey('items', $result);
        $this->assertCount(1, $result['items']);
        $this->assertSame('Notebook', $result['items'][0]['title']);
        $this->assertSame(12, $result['items'][0]['installmentsCount']);
    }

    public function testCreatePlanCreatesItemsWithinTransaction(): void
    {
        $this->connection->expects($this->once())
            ->method('fetchAllAssociative')
            ->with(
                $this->callback(fn(string $sql): bool => str_contains($sql, 'finance_category')),
                $this->callback(fn(array $params): bool => $params['ownerId'] === 42),
            )
            ->willReturn([['id' => 10, 'name' => 'Equipamentos', 'is_active' => 1]]);

        $this->connection->expects($this->once())
            ->method('transactional')
            ->willReturnCallback(fn(callable $fn) => $fn($this->connection));

        $this->connection->expects($this->once())
            ->method('insert')
            ->with('finance_installment_plan', $this->callback(function (array $data): bool {
                $this->assertSame(42, $data['owner_id']);
                $this->assertSame('Notebook', $data['title']);
                $this->assertSame(5000.0, $data['total_amount_brl']);

                return true;
            }));

        $this->connection->expects($this->once())
            ->method('lastInsertId')
            ->willReturn('77');

        $this->entryService->expects($this->exactly(12))
            ->method('createEntry')
            ->willReturn(['id' => 1, 'title' => 'Notebook parcela']);

        $this->connection->expects($this->any())
            ->method('fetchAssociative')
            ->willReturnCallback(fn(string $sql, array $params): array => match (true) {
                str_contains($sql, 'finance_installment_plan') => [
                    'id' => 77,
                    'direction' => 'PAYABLE',
                    'title' => 'Notebook',
                    'totalAmountBrl' => 5000.00,
                    'installmentsCount' => 12,
                    'downPaymentBrl' => 0.0,
                    'status' => 'ACTIVE',
                    'remainingAmountBrl' => 5000.00,
                ],
                default => [],
            });

        $this->connection->expects($this->any())
            ->method('fetchAllAssociative')
            ->willReturn([]);

        $result = $this->service->createPlan($this->user, [
            'direction' => 'PAYABLE',
            'title' => 'Notebook',
            'totalAmountBrl' => 5000.00,
            'installmentsCount' => 12,
            'categoryId' => 10,
        ]);

        $this->assertSame(77, $result['id']);
    }

    public function testListPlansReturnsEmptyWhenNoPlans(): void
    {
        $this->connection->expects($this->once())
            ->method('fetchAllAssociative')
            ->willReturn([]);

        $result = $this->service->listPlans($this->user);

        $this->assertSame([], $result['items']);
    }
}
