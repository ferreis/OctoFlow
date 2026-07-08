<?php

namespace App\Tests\Unit\Finance;

use App\Entity\User;
use App\Finance\FinanceCurrencyService;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

final class FinanceCurrencyServiceTest extends TestCase
{
    private Connection $connection;
    private FinanceCurrencyService $service;
    private User $user;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(Connection::class);
        $this->service = new FinanceCurrencyService($this->connection);
        $this->user = (new User())
            ->setEmail('currency-test@example.com')
            ->setPassword('not-used')
            ->setRoles(['ROLE_FINANCE_READ']);
        $this->user->setId(42);
    }

    public function testListCurrenciesReturnsCatalog(): void
    {
        $this->connection->expects($this->once())
            ->method('fetchAllAssociative')
            ->willReturn([
                ['code' => 'USD', 'name' => 'Dólar Americano'],
                ['code' => 'EUR', 'name' => 'Euro'],
            ]);

        $result = $this->service->listCurrencies();

        $this->assertCount(2, $result['items']);
        $this->assertSame('USD', $result['items'][0]['code']);
        $this->assertSame('Euro', $result['items'][1]['name']);
    }

    public function testCreateManualRateValidatesCurrencyCode(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('currency code must be informed with 3 letters');

        $this->service->createManualRate($this->user, [
            'currencyCode' => 'INVALID',
            'rateBrl' => 5.50,
            'quoteDate' => '2026-07-07',
        ]);
    }

    public function testCreateManualRateValidatesPositiveRate(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('manual rate must be greater than zero');

        $this->service->createManualRate($this->user, [
            'currencyCode' => 'USD',
            'rateBrl' => 0,
            'quoteDate' => '2026-07-07',
        ]);
    }

    public function testCreateManualRateInsertsRate(): void
    {
        $this->connection->expects($this->once())
            ->method('fetchOne')
            ->willReturn('1');

        $this->connection->expects($this->once())
            ->method('executeStatement')
            ->with(
                $this->callback(fn(string $sql): bool => str_contains($sql, 'finance_currency_rate')),
                $this->callback(fn(array $params): bool => $params['owner_id'] === 42),
            );

        $result = $this->service->createManualRate($this->user, [
            'currencyCode' => 'USD',
            'currencyName' => 'Dólar Americano',
            'rateBrl' => 5.50,
            'quoteDate' => '2026-07-07',
        ]);

        $this->assertSame('USD', $result['item']['code']);
        $this->assertSame(5.5, $result['item']['rateBrl']);
    }
}
