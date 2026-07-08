<?php

namespace App\Tests\Unit\Finance;

use App\Finance\FinanceCatalogService;
use App\Finance\FinanceEntryService;
use App\Finance\FinanceRecurringService;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

final class FinanceEntryServiceTest extends TestCase
{
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
}
