<?php

namespace App\Finance;

final class FinanceConstants
{
    public const DIRECTION_PAYABLE = 'PAYABLE';
    public const DIRECTION_RECEIVABLE = 'RECEIVABLE';

    /**
     * @var list<string>
     */
    public const ALLOWED_DIRECTIONS = [
        self::DIRECTION_PAYABLE,
        self::DIRECTION_RECEIVABLE,
    ];

    /**
     * @var list<string>
     */
    public const PAYABLE_STATUSES = [
        'PENDING',
        'PAID',
        'PARTIAL',
        'OVERDUE',
        'SCHEDULED',
        'CANCELED',
        'NEGOTIATED',
    ];

    /**
     * @var list<string>
     */
    public const RECEIVABLE_STATUSES = [
        'FORECAST',
        'RECEIVED',
        'PARTIAL',
        'OVERDUE',
        'CANCELED',
    ];

    public const ENTRY_TYPE_ONE_OFF = 'ONE_OFF';
    public const ENTRY_TYPE_RECURRING = 'RECURRING';
    public const ENTRY_TYPE_INSTALLMENT = 'INSTALLMENT';
    public const ENTRY_TYPE_NEGOTIATION = 'NEGOTIATION';
    public const ENTRY_TYPE_INVESTMENT_CONTRIBUTION = 'INVESTMENT_CONTRIBUTION';
    public const ENTRY_TYPE_INVESTMENT_YIELD = 'INVESTMENT_YIELD';

    /**
     * @var list<string>
     */
    public const ENTRY_TYPES = [
        self::ENTRY_TYPE_ONE_OFF,
        self::ENTRY_TYPE_RECURRING,
        self::ENTRY_TYPE_INSTALLMENT,
        self::ENTRY_TYPE_NEGOTIATION,
        self::ENTRY_TYPE_INVESTMENT_CONTRIBUTION,
        self::ENTRY_TYPE_INVESTMENT_YIELD,
        'DEBT',
        'ADJUSTMENT',
    ];

    public const SOURCE_ORIGIN_MANUAL = 'MANUAL';
    public const SOURCE_ORIGIN_SYSTEM = 'SYSTEM';
    public const SOURCE_ORIGIN_IMPORTED = 'IMPORTED';

    /**
     * @var list<string>
     */
    public const SOURCE_ORIGINS = [
        self::SOURCE_ORIGIN_MANUAL,
        self::SOURCE_ORIGIN_SYSTEM,
        self::SOURCE_ORIGIN_IMPORTED,
    ];

    /**
     * @var list<string>
     */
    public const INVESTMENT_TYPES = [
        'SELIC',
        'CDB',
        'CDI',
        'TESOURO',
        'CUSTOM',
    ];

    /**
     * @var list<string>
     */
    public const INVESTMENT_YIELD_MODES = [
        'NONE',
        'ESTIMATED',
        'MANUAL',
    ];

    /**
     * @var list<string>
     */
    public const EXPORT_TYPES = [
        'PAYABLE',
        'RECEIVABLE',
        'MONTHLY_SUMMARY',
        'CATEGORY',
        'CASHFLOW',
        'INVESTMENT',
    ];

    /**
     * @var list<string>
     */
    public const EXPORT_STATUSES = [
        'QUEUED',
        'PROCESSING',
        'DONE',
        'FAILED',
        'EXPIRED',
    ];

    private function __construct()
    {
    }
}
