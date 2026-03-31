<?php

namespace App\Finance;

use App\Entity\User;
use Doctrine\DBAL\Connection;

final class FinanceDashboardService
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * @param array<string, mixed> $filters
     *
     * @return array<string, mixed>
     */
    public function summary(User $user, array $filters = []): array
    {
        $ownerId = $this->requireOwnerId($user);
        [$whereSql, $parameters] = $this->buildEntryWhere($ownerId, $filters);

        /** @var array<string, mixed>|false $row */
        $row = $this->connection->fetchAssociative(<<<SQL
            SELECT
                COALESCE(SUM(CASE WHEN entry.direction = 'RECEIVABLE' THEN entry.expected_amount_brl ELSE 0 END), 0) AS expected_income_brl,
                COALESCE(SUM(CASE WHEN entry.direction = 'PAYABLE' THEN entry.expected_amount_brl ELSE 0 END), 0) AS expected_expense_brl,
                COALESCE(SUM(CASE WHEN entry.direction = 'RECEIVABLE' THEN entry.settled_amount_brl ELSE 0 END), 0) AS realized_income_brl,
                COALESCE(SUM(CASE WHEN entry.direction = 'PAYABLE' THEN entry.settled_amount_brl ELSE 0 END), 0) AS realized_expense_brl,
                COALESCE(SUM(CASE
                    WHEN entry.direction = 'RECEIVABLE' THEN entry.remaining_amount_brl
                    ELSE -entry.remaining_amount_brl
                END), 0) AS remaining_total_brl,
                COALESCE(SUM(CASE WHEN entry.status = 'OVERDUE' THEN 1 ELSE 0 END), 0) AS overdue_entries_count
            FROM finance_entry entry
            WHERE {$whereSql}
        SQL, $parameters);

        if (!is_array($row)) {
            throw new \RuntimeException('Failed to calculate finance summary.');
        }

        $expectedIncomeBrl = (float) $row['expected_income_brl'];
        $expectedExpenseBrl = (float) $row['expected_expense_brl'];
        $realizedIncomeBrl = (float) $row['realized_income_brl'];
        $realizedExpenseBrl = (float) $row['realized_expense_brl'];

        $accountsBalanceBrl = (float) $this->connection->fetchOne(<<<'SQL'
            SELECT COALESCE(SUM(current_balance_brl), 0)
            FROM finance_bank_account
            WHERE owner_id = :ownerId
              AND is_active = true
        SQL, [
            'ownerId' => $ownerId,
        ]);

        return [
            'expectedIncomeBrl' => round($expectedIncomeBrl, 2),
            'expectedExpenseBrl' => round($expectedExpenseBrl, 2),
            'realizedIncomeBrl' => round($realizedIncomeBrl, 2),
            'realizedExpenseBrl' => round($realizedExpenseBrl, 2),
            'expectedNetBrl' => round($expectedIncomeBrl - $expectedExpenseBrl, 2),
            'realizedNetBrl' => round($realizedIncomeBrl - $realizedExpenseBrl, 2),
            'remainingTotalBrl' => round((float) $row['remaining_total_brl'], 2),
            'overdueEntriesCount' => (int) $row['overdue_entries_count'],
            'accountsBalanceBrl' => round($accountsBalanceBrl, 2),
        ];
    }

    /**
     * @param array<string, mixed> $filters
     *
     * @return array{items: list<array<string, mixed>>}
     */
    public function cashflow(User $user, array $filters = []): array
    {
        $ownerId = $this->requireOwnerId($user);

        $startMonth = FinanceInput::normalizeOptionalDate($filters['startMonth'] ?? null)
            ?? new \DateTimeImmutable('first day of -5 months');
        $endMonth = FinanceInput::normalizeOptionalDate($filters['endMonth'] ?? null)
            ?? new \DateTimeImmutable('first day of +1 month');

        $startMonth = new \DateTimeImmutable($startMonth->format('Y-m-01'));
        $endMonth = new \DateTimeImmutable($endMonth->format('Y-m-01'));

        if ($endMonth < $startMonth) {
            throw new \InvalidArgumentException('endMonth cannot be earlier than startMonth.');
        }

        $filtersWithDateRange = $filters;
        $filtersWithDateRange['startDate'] = $startMonth->format('Y-m-01');
        $filtersWithDateRange['endDate'] = $endMonth->modify('last day of this month')->format('Y-m-d');

        [$whereSql, $parameters] = $this->buildEntryWhere($ownerId, $filtersWithDateRange);

        /** @var list<array<string, mixed>> $items */
        $items = $this->connection->fetchAllAssociative(<<<SQL
            SELECT
                TO_CHAR(COALESCE(entry.competence_month, entry.due_date), 'YYYY-MM-01') AS competence_month,
                COALESCE(SUM(CASE WHEN entry.direction = 'RECEIVABLE' THEN entry.expected_amount_brl ELSE 0 END), 0) AS expected_income_brl,
                COALESCE(SUM(CASE WHEN entry.direction = 'PAYABLE' THEN entry.expected_amount_brl ELSE 0 END), 0) AS expected_expense_brl,
                COALESCE(SUM(CASE WHEN entry.direction = 'RECEIVABLE' THEN entry.settled_amount_brl ELSE 0 END), 0) AS realized_income_brl,
                COALESCE(SUM(CASE WHEN entry.direction = 'PAYABLE' THEN entry.settled_amount_brl ELSE 0 END), 0) AS realized_expense_brl
            FROM finance_entry entry
            WHERE {$whereSql}
              AND COALESCE(entry.competence_month, entry.due_date) IS NOT NULL
            GROUP BY TO_CHAR(COALESCE(entry.competence_month, entry.due_date), 'YYYY-MM-01')
            ORDER BY competence_month ASC
        SQL, $parameters);

        $normalizedItems = array_map(static function (array $item): array {
            $expectedIncomeBrl = (float) $item['expected_income_brl'];
            $expectedExpenseBrl = (float) $item['expected_expense_brl'];
            $realizedIncomeBrl = (float) $item['realized_income_brl'];
            $realizedExpenseBrl = (float) $item['realized_expense_brl'];

            return [
                'competenceMonth' => (string) $item['competence_month'],
                'expectedIncomeBrl' => round($expectedIncomeBrl, 2),
                'expectedExpenseBrl' => round($expectedExpenseBrl, 2),
                'expectedNetBrl' => round($expectedIncomeBrl - $expectedExpenseBrl, 2),
                'realizedIncomeBrl' => round($realizedIncomeBrl, 2),
                'realizedExpenseBrl' => round($realizedExpenseBrl, 2),
                'realizedNetBrl' => round($realizedIncomeBrl - $realizedExpenseBrl, 2),
            ];
        }, $items);

        return [
            'items' => $normalizedItems,
        ];
    }

    /**
     * @param array<string, mixed> $filters
     *
     * @return array{items: list<array<string, mixed>>}
     */
    public function categories(User $user, array $filters = []): array
    {
        $ownerId = $this->requireOwnerId($user);
        [$whereSql, $parameters] = $this->buildEntryWhere($ownerId, $filters);

        $limit = max(1, min(50, (int) ($filters['limit'] ?? 12)));
        $parameters['limit'] = $limit;

        /** @var list<array<string, mixed>> $items */
        $items = $this->connection->fetchAllAssociative(<<<SQL
            SELECT
                COALESCE(category.id, 0) AS category_id,
                COALESCE(category.name, 'Sem categoria') AS category_name,
                COALESCE(SUM(CASE WHEN entry.direction = 'RECEIVABLE' THEN entry.expected_amount_brl ELSE 0 END), 0) AS expected_income_brl,
                COALESCE(SUM(CASE WHEN entry.direction = 'PAYABLE' THEN entry.expected_amount_brl ELSE 0 END), 0) AS expected_expense_brl,
                COALESCE(SUM(CASE WHEN entry.direction = 'RECEIVABLE' THEN entry.settled_amount_brl ELSE 0 END), 0) AS realized_income_brl,
                COALESCE(SUM(CASE WHEN entry.direction = 'PAYABLE' THEN entry.settled_amount_brl ELSE 0 END), 0) AS realized_expense_brl,
                COUNT(entry.id) AS entries_count
            FROM finance_entry entry
            LEFT JOIN finance_category category ON category.id = entry.category_id
            WHERE {$whereSql}
            GROUP BY COALESCE(category.id, 0), COALESCE(category.name, 'Sem categoria')
            ORDER BY (COALESCE(SUM(entry.expected_amount_brl), 0)) DESC
            LIMIT :limit
        SQL, $parameters);

        $normalizedItems = array_map(static function (array $item): array {
            $expectedIncomeBrl = (float) $item['expected_income_brl'];
            $expectedExpenseBrl = (float) $item['expected_expense_brl'];
            $realizedIncomeBrl = (float) $item['realized_income_brl'];
            $realizedExpenseBrl = (float) $item['realized_expense_brl'];

            return [
                'categoryId' => ((int) $item['category_id']) > 0 ? (int) $item['category_id'] : null,
                'categoryName' => (string) $item['category_name'],
                'entriesCount' => (int) $item['entries_count'],
                'expectedIncomeBrl' => round($expectedIncomeBrl, 2),
                'expectedExpenseBrl' => round($expectedExpenseBrl, 2),
                'expectedNetBrl' => round($expectedIncomeBrl - $expectedExpenseBrl, 2),
                'realizedIncomeBrl' => round($realizedIncomeBrl, 2),
                'realizedExpenseBrl' => round($realizedExpenseBrl, 2),
                'realizedNetBrl' => round($realizedIncomeBrl - $realizedExpenseBrl, 2),
            ];
        }, $items);

        return [
            'items' => $normalizedItems,
        ];
    }

    /**
     * @param array<string, mixed> $filters
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function buildEntryWhere(int $ownerId, array $filters): array
    {
        $whereParts = [
            'entry.owner_id = :ownerId',
            'entry.deleted_at IS NULL',
        ];
        $parameters = ['ownerId' => $ownerId];

        $direction = strtoupper(trim((string) ($filters['direction'] ?? '')));
        if ($direction !== '') {
            $whereParts[] = 'entry.direction = :direction';
            $parameters['direction'] = $direction;
        }

        $status = strtoupper(trim((string) ($filters['status'] ?? '')));
        if ($status !== '') {
            $whereParts[] = 'entry.status = :status';
            $parameters['status'] = $status;
        }

        $sourceOrigin = strtoupper(trim((string) ($filters['sourceOrigin'] ?? '')));
        if ($sourceOrigin !== '') {
            $whereParts[] = 'entry.source_origin = :sourceOrigin';
            $parameters['sourceOrigin'] = $sourceOrigin;
        }

        $categoryId = (int) ($filters['categoryId'] ?? 0);
        if ($categoryId > 0) {
            $whereParts[] = 'entry.category_id = :categoryId';
            $parameters['categoryId'] = $categoryId;
        }

        $bankAccountId = (int) ($filters['bankAccountId'] ?? 0);
        if ($bankAccountId > 0) {
            $whereParts[] = 'entry.bank_account_id = :bankAccountId';
            $parameters['bankAccountId'] = $bankAccountId;
        }

        $startDate = FinanceInput::normalizeOptionalDate($filters['startDate'] ?? null);
        if ($startDate instanceof \DateTimeImmutable) {
            $whereParts[] = 'entry.due_date >= :startDate';
            $parameters['startDate'] = $startDate->format('Y-m-d');
        }

        $endDate = FinanceInput::normalizeOptionalDate($filters['endDate'] ?? null);
        if ($endDate instanceof \DateTimeImmutable) {
            $whereParts[] = 'entry.due_date <= :endDate';
            $parameters['endDate'] = $endDate->format('Y-m-d');
        }

        $competenceMonth = FinanceInput::normalizeOptionalDate($filters['competenceMonth'] ?? null);
        if ($competenceMonth instanceof \DateTimeImmutable) {
            $whereParts[] = 'entry.competence_month = :competenceMonth';
            $parameters['competenceMonth'] = $competenceMonth->format('Y-m-01');
        }

        return [implode(' AND ', $whereParts), $parameters];
    }

    private function requireOwnerId(User $user): int
    {
        $ownerId = (int) $user->getId();
        if ($ownerId <= 0) {
            throw new \InvalidArgumentException('Invalid user context.');
        }

        return $ownerId;
    }
}
