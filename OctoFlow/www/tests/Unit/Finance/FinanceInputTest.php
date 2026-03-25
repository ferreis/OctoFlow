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
