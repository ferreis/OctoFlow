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
            throw new \InvalidArgumentException('The informed direction is invalid. Use PAYABLE or RECEIVABLE.');
        }

        return $normalizedDirection;
    }

    public static function normalizeMoney(mixed $value, string $fieldName): float
    {
        if (is_string($value)) {
            $value = str_replace(',', '.', trim($value));
        }

        if (!is_numeric($value)) {
            throw new \InvalidArgumentException(sprintf('The field "%s" must be numeric.', $fieldName));
        }

        return round((float) $value, 2);
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
            throw new \InvalidArgumentException('The informed money value is invalid.');
        }

        return round((float) $value, 2);
    }

    public static function normalizeDate(mixed $value, string $fieldName): \DateTimeImmutable
    {
        $normalizedValue = trim((string) $value);
        if ($normalizedValue === '') {
            throw new \InvalidArgumentException(sprintf('The field "%s" is required.', $fieldName));
        }

        try {
            return new \DateTimeImmutable($normalizedValue);
        } catch (\Throwable) {
            throw new \InvalidArgumentException(sprintf('The field "%s" has an invalid date.', $fieldName));
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
            throw new \InvalidArgumentException('The informed date is invalid.');
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
            throw new \InvalidArgumentException('The informed status is invalid for the selected direction.');
        }

        return $normalizedStatus;
    }

    public static function defaultStatusByDirection(string $direction, ?\DateTimeImmutable $dueDate): string
    {
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
            throw new \InvalidArgumentException('The rate input type must be MONTHLY or ANNUAL.');
        }

        return $normalizedType;
    }

    private function __construct()
    {
    }
}
