<?php

namespace App\Tests\Unit\Finance;

use App\Finance\FinanceInput;
use PHPUnit\Framework\TestCase;

final class FinanceInputTest extends TestCase
{
    public function testNormalizeDirectionAcceptsPayableAndReceivable(): void
    {
        $this->assertSame('PAYABLE', FinanceInput::normalizeDirection('payable'));
        $this->assertSame('RECEIVABLE', FinanceInput::normalizeDirection('RECEIVABLE'));
    }

    public function testNormalizeDirectionRejectsInvalidValues(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        FinanceInput::normalizeDirection('TRANSFER');
    }

    public function testNormalizeMoneyAcceptsCommaAndRounds(): void
    {
        $this->assertSame(85.13, FinanceInput::normalizeMoney('85,129', 'amountBrl'));
    }

    public function testMoneyConversionUsesIntegerCents(): void
    {
        $this->assertSame(8513, FinanceInput::moneyToCents('85,129', 'amountBrl'));
        $this->assertSame(85.13, FinanceInput::moneyFromCents(8513));
    }

    public function testNormalizeMoneyRejectsNegativeValues(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(FinanceInputTest::getExpectedNegativeMessage());
        FinanceInput::normalizeMoney('-50', 'amountBrl');
    }

    public function testNormalizeSignedMoneyAcceptsNegativeBankBalance(): void
    {
        $this->assertSame(-125.75, FinanceInput::normalizeSignedMoney('-125,75', 'currentBalanceBrl'));
        $this->assertSame(-12575, FinanceInput::signedMoneyToCents('-125,75', 'currentBalanceBrl'));
    }

    private static function getExpectedNegativeMessage(): string
    {
        return 'O valor nao pode ser negativo.';
    }

    public function testNormalizeStatusValidatesByDirection(): void
    {
        $this->assertSame('PENDING', FinanceInput::normalizeStatus('PAYABLE', 'pending'));
        $this->assertSame('FORECAST', FinanceInput::normalizeStatus('RECEIVABLE', 'forecast'));
    }

    public function testNormalizeStatusRejectsStatusNotAllowedForDirection(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        FinanceInput::normalizeStatus('PAYABLE', 'RECEIVED');
    }

    public function testDefaultStatusByDirectionUsesScheduleForFuturePayable(): void
    {
        $futureDate = new \DateTimeImmutable('tomorrow');
        $this->assertSame('SCHEDULED', FinanceInput::defaultStatusByDirection('PAYABLE', $futureDate));
        $this->assertSame('FORECAST', FinanceInput::defaultStatusByDirection('RECEIVABLE', $futureDate));
    }

    public function testDefaultStatusByDirectionUsesDueDate(): void
    {
        $this->assertSame('OVERDUE', FinanceInput::defaultStatusByDirection('PAYABLE', new \DateTimeImmutable('yesterday')));
        $this->assertSame('PENDING', FinanceInput::defaultStatusByDirection('PAYABLE', new \DateTimeImmutable('today')));
        $this->assertSame('PENDING', FinanceInput::defaultStatusByDirection('RECEIVABLE', null));
    }

    public function testConvertAnnualRateToMonthlyUsesCompoundFormula(): void
    {
        $effectiveMonthlyRate = FinanceInput::convertAnnualRateToMonthly(12.0);

        $this->assertGreaterThan(0.0094, $effectiveMonthlyRate);
        $this->assertLessThan(0.0095, $effectiveMonthlyRate);
    }

    public function testToDatabaseBooleanReturnsNumericBoolean(): void
    {
        $this->assertSame(1, FinanceInput::toDatabaseBoolean(true));
        $this->assertSame(0, FinanceInput::toDatabaseBoolean(false));
    }
}
