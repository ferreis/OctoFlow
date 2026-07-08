<?php

namespace App\Finance;

final class FinanceInput
{
    public static function normalizeName(string $value): string
    {
        return preg_replace('/\s+/', ' ', trim($value)) ?? '';
    }

    public static function normalizeNameKey(string $value): string
    {
        return mb_strtolower(self::normalizeName($value));
    }

    public static function normalizeDirection(mixed $value): string
    {
        $normalizedDirection = strtoupper(trim((string) $value));
        if (!in_array($normalizedDirection, FinanceConstants::ALLOWED_DIRECTIONS, true)) {
            throw new \InvalidArgumentException(FinanceErrorMessages::FIELD_INVALID);
        }

        return $normalizedDirection;
    }

    public static function normalizeMoney(mixed $value, string $fieldName): float
    {
        if (is_string($value)) {
            $value = str_replace(',', '.', trim($value));
        }

        if (!is_numeric($value)) {
            throw new \InvalidArgumentException(sprintf(FinanceErrorMessages::NUMERIC_FIELD_REQUIRED, $fieldName));
        }

        $amount = round((float) $value, 2);
        if ($amount < 0) {
            throw new \InvalidArgumentException(sprintf(FinanceErrorMessages::AMOUNT_CANNOT_BE_NEGATIVE));
        }

        return $amount;
    }

    public static function moneyToCents(mixed $value, string $fieldName = 'amount'): int
    {
        return (int) round(self::normalizeMoney($value, $fieldName) * 100);
    }

    public static function moneyFromCents(int $cents): float
    {
        return round($cents / 100, 2);
    }

    public static function normalizeOptionalMoney(mixed $value, ?float $fallback = null): ?float
    {
        if ($value === null || $value === '') {
            return $fallback;
        }

        if (is_string($value)) {
            $value = str_replace(',', '.', trim($value));
        }

        if (!is_numeric($value)) {
            throw new \InvalidArgumentException(FinanceErrorMessages::FIELD_INVALID);
        }

        return round((float) $value, 2);
    }

    public static function normalizeDate(mixed $value, string $fieldName): \DateTimeImmutable
    {
        $normalizedValue = trim((string) $value);
        if ($normalizedValue === '') {
            throw new \InvalidArgumentException(sprintf(FinanceErrorMessages::DATE_REQUIRED, $fieldName));
        }

        try {
            return new \DateTimeImmutable($normalizedValue);
        } catch (\Throwable) {
            throw new \InvalidArgumentException(sprintf(FinanceErrorMessages::DATE_FIELD_INVALID, $fieldName));
        }
    }

    public static function normalizeOptionalDate(mixed $value): ?\DateTimeImmutable
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable((string) $value);
        } catch (\Throwable) {
            throw new \InvalidArgumentException(FinanceErrorMessages::DATE_INVALID);
        }
    }

    public static function normalizeStatus(string $direction, mixed $status, bool $allowEmpty = true): ?string
    {
        $normalizedStatus = strtoupper(trim((string) $status));
        if ($normalizedStatus === '') {
            return $allowEmpty ? null : self::defaultStatusByDirection($direction, null);
        }

        $allowedStatuses = $direction === FinanceConstants::DIRECTION_PAYABLE
            ? FinanceConstants::PAYABLE_STATUSES
            : FinanceConstants::RECEIVABLE_STATUSES;

        if (!in_array($normalizedStatus, $allowedStatuses, true)) {
            throw new \InvalidArgumentException(FinanceErrorMessages::FIELD_INVALID);
        }

        return $normalizedStatus;
    }

    public static function defaultStatusByDirection(string $direction, ?\DateTimeImmutable $dueDate): string
    {
        if (!$dueDate instanceof \DateTimeImmutable) {
            return 'PENDING';
        }

        $normalizedDirection = strtoupper(trim($direction));
        $today = new \DateTimeImmutable('today');
        $normalizedDueDate = new \DateTimeImmutable($dueDate->format('Y-m-d'));

        if ($normalizedDueDate < $today) {
            return 'OVERDUE';
        }

        if ($normalizedDueDate > $today) {
            return $normalizedDirection === FinanceConstants::DIRECTION_RECEIVABLE ? 'FORECAST' : 'SCHEDULED';
        }

        return 'PENDING';
    }

    /**
     * @return array{page: int, itemsPerPage: int, offset: int}
     */
    public static function normalizePagination(mixed $page, mixed $itemsPerPage): array
    {
        $normalizedPage = max(1, (int) $page);
        $normalizedItemsPerPage = max(5, min(10, (int) $itemsPerPage));

        return [
            'page' => $normalizedPage,
            'itemsPerPage' => $normalizedItemsPerPage,
            'offset' => ($normalizedPage - 1) * $normalizedItemsPerPage,
        ];
    }

    public static function normalizeBoolean(mixed $value, bool $fallback = false): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value !== 0;
        }

        if (is_string($value)) {
            $normalizedValue = mb_strtolower(trim($value));

            if (in_array($normalizedValue, ['1', 'true', 'yes', 'y', 'on'], true)) {
                return true;
            }

            if (in_array($normalizedValue, ['0', 'false', 'no', 'n', 'off'], true)) {
                return false;
            }
        }

        return $fallback;
    }

    public static function toDatabaseBoolean(bool $value): int
    {
        return $value ? 1 : 0;
    }

    public static function convertAnnualRateToMonthly(float $annualRatePercent): float
    {
        $annualRateDecimal = $annualRatePercent / 100;

        return pow(1 + $annualRateDecimal, 1 / 12) - 1;
    }

    public static function normalizeRateInputType(mixed $value): string
    {
        $normalizedType = strtoupper(trim((string) $value));
        if (!in_array($normalizedType, ['MONTHLY', 'ANNUAL'], true)) {
            throw new \InvalidArgumentException(FinanceErrorMessages::FIELD_INVALID);
        }

        return $normalizedType;
    }

    private function __construct()
    {
    }
}
