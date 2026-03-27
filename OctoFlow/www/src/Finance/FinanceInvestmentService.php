<?php

namespace App\Finance;

use App\Entity\User;
use Doctrine\DBAL\Connection;

final class FinanceInvestmentService
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function createSimulation(User $user, array $payload): array
    {
        $ownerId = $this->requireOwnerId($user);

        $investmentType = strtoupper(trim((string) ($payload['investmentType'] ?? 'CUSTOM')));
        if (!in_array($investmentType, FinanceConstants::INVESTMENT_TYPES, true)) {
            throw new \InvalidArgumentException('The informed investment type is invalid.');
        }

        $label = FinanceInput::normalizeName((string) ($payload['label'] ?? 'Investment simulation'));
        $initialAmountBrl = FinanceInput::normalizeMoney($payload['initialAmountBrl'] ?? 0, 'initialAmountBrl');
        $monthlyContributionBrl = FinanceInput::normalizeMoney($payload['monthlyContributionBrl'] ?? 0, 'monthlyContributionBrl');
        $periodMonths = (int) ($payload['periodMonths'] ?? 0);
        $rateInputType = FinanceInput::normalizeRateInputType($payload['rateInputType'] ?? 'MONTHLY');
        $rateValue = FinanceInput::normalizeMoney($payload['rateValue'] ?? 0, 'rateValue');

        if ($periodMonths <= 0) {
            throw new \InvalidArgumentException('The period in months must be greater than zero.');
        }

        if ($initialAmountBrl < 0 || $monthlyContributionBrl < 0) {
            throw new \InvalidArgumentException('Initial amount and monthly contribution cannot be negative.');
        }

        if ($rateValue < 0) {
            throw new \InvalidArgumentException('The informed rate cannot be negative.');
        }

        $effectiveMonthlyRate = $rateInputType === 'ANNUAL'
            ? FinanceInput::convertAnnualRateToMonthly($rateValue)
            : ($rateValue / 100);

        $investedAmount = round($initialAmountBrl, 2);
        $accumulatedYieldAmount = 0.0;
        $currentBalance = round($initialAmountBrl, 2);

        $points = [];
        for ($monthIndex = 1; $monthIndex <= $periodMonths; $monthIndex += 1) {
            $currentBalance = round($currentBalance + $monthlyContributionBrl, 2);
            $investedAmount = round($investedAmount + $monthlyContributionBrl, 2);

            $monthYieldAmount = round($currentBalance * $effectiveMonthlyRate, 2);
            $accumulatedYieldAmount = round($accumulatedYieldAmount + $monthYieldAmount, 2);
            $currentBalance = round($currentBalance + $monthYieldAmount, 2);

            $points[] = [
                'monthIndex' => $monthIndex,
                'investedAmountBrl' => $investedAmount,
                'yieldAmountBrl' => $accumulatedYieldAmount,
                'totalAmountBrl' => $currentBalance,
            ];
        }

        $createdAt = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        return $this->connection->transactional(function () use (
            $ownerId,
            $investmentType,
            $label,
            $initialAmountBrl,
            $monthlyContributionBrl,
            $periodMonths,
            $rateInputType,
            $rateValue,
            $effectiveMonthlyRate,
            $investedAmount,
            $accumulatedYieldAmount,
            $currentBalance,
            $points,
            $createdAt,
        ): array {
            $this->connection->insert('finance_investment_simulation', [
                'owner_id' => $ownerId,
                'investment_type' => $investmentType,
                'label' => $label,
                'initial_amount_brl' => $initialAmountBrl,
                'monthly_contribution_brl' => $monthlyContributionBrl,
                'period_months' => $periodMonths,
                'rate_input_type' => $rateInputType,
                'rate_value' => $rateValue,
                'effective_monthly_rate' => $effectiveMonthlyRate,
                'total_invested_brl' => $investedAmount,
                'total_yield_brl' => $accumulatedYieldAmount,
                'final_amount_brl' => $currentBalance,
                'created_at' => $createdAt,
            ]);

            $simulationId = (int) $this->connection->lastInsertId();

            foreach ($points as $point) {
                $this->connection->insert('finance_investment_simulation_point', [
                    'simulation_id' => $simulationId,
                    'month_index' => $point['monthIndex'],
                    'invested_amount_brl' => $point['investedAmountBrl'],
                    'yield_amount_brl' => $point['yieldAmountBrl'],
                    'total_amount_brl' => $point['totalAmountBrl'],
                ]);
            }

            return $this->getSimulationById($ownerId, $simulationId);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function getSimulation(User $user, int $simulationId): array
    {
        $ownerId = $this->requireOwnerId($user);

        return $this->getSimulationById($ownerId, $simulationId);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function convertSimulationToPlan(User $user, int $simulationId, array $payload): array
    {
        $ownerId = $this->requireOwnerId($user);
        $simulation = $this->getSimulationById($ownerId, $simulationId);

        $label = FinanceInput::normalizeName((string) ($payload['label'] ?? ($simulation['label'] . ' plan')));
        $startDate = FinanceInput::normalizeDate($payload['startDate'] ?? 'today', 'startDate');
        $contributionDay = (int) ($payload['contributionDay'] ?? (int) $startDate->format('d'));
        $generateYieldEntries = FinanceInput::normalizeBoolean($payload['generateYieldEntries'] ?? false, false);
        $yieldMode = strtoupper(trim((string) ($payload['yieldMode'] ?? ($generateYieldEntries ? 'ESTIMATED' : 'NONE'))));
        if (!in_array($yieldMode, FinanceConstants::INVESTMENT_YIELD_MODES, true)) {
            throw new \InvalidArgumentException('The investment yield mode is invalid.');
        }

        if ($contributionDay < 1 || $contributionDay > 31) {
            throw new \InvalidArgumentException('The contribution day must be between 1 and 31.');
        }

        $defaultBankAccountId = $this->normalizeOwnedBankAccountId($ownerId, $payload['defaultBankAccountId'] ?? null);
        $categoryId = $this->normalizeOwnedCategoryId($ownerId, $payload['categoryId'] ?? null);

        return $this->connection->transactional(function () use (
            $ownerId,
            $simulation,
            $label,
            $startDate,
            $contributionDay,
            $generateYieldEntries,
            $yieldMode,
            $defaultBankAccountId,
            $categoryId,
        ): array {
            $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

            $this->connection->insert('finance_investment_plan', [
                'owner_id' => $ownerId,
                'source_simulation_id' => (int) $simulation['id'],
                'default_bank_account_id' => $defaultBankAccountId,
                'category_id' => $categoryId,
                'label' => $label,
                'investment_type' => (string) $simulation['investmentType'],
                'start_date' => $startDate->format('Y-m-d'),
                'contribution_day' => $contributionDay,
                'monthly_contribution_brl' => (float) $simulation['monthlyContributionBrl'],
                'effective_monthly_rate' => (float) $simulation['effectiveMonthlyRate'],
                'generate_yield_entries' => FinanceInput::toDatabaseBoolean($generateYieldEntries),
                'yield_mode' => $yieldMode,
                'status' => 'ACTIVE',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $planId = (int) $this->connection->lastInsertId();

            if ((float) $simulation['initialAmountBrl'] > 0.00001) {
                $initialEntryStatus = FinanceInput::defaultStatusByDirection(FinanceConstants::DIRECTION_PAYABLE, $startDate);
                $this->connection->insert('finance_entry', [
                    'owner_id' => $ownerId,
                    'category_id' => $categoryId,
                    'bank_account_id' => $defaultBankAccountId,
                    'direction' => FinanceConstants::DIRECTION_PAYABLE,
                    'entry_type' => FinanceConstants::ENTRY_TYPE_INVESTMENT_CONTRIBUTION,
                    'status' => $initialEntryStatus,
                    'title' => sprintf('%s - Initial contribution', $label),
                    'description' => 'Generated from investment plan conversion.',
                    'due_date' => $startDate->format('Y-m-d'),
                    'competence_month' => $startDate->format('Y-m-01'),
                    'expected_amount_brl' => (float) $simulation['initialAmountBrl'],
                    'settled_amount_brl' => 0,
                    'remaining_amount_brl' => (float) $simulation['initialAmountBrl'],
                    'input_currency_code' => 'BRL',
                    'input_amount' => (float) $simulation['initialAmountBrl'],
                    'fx_rate_to_brl' => 1,
                    'fx_rate_date' => $startDate->format('Y-m-d'),
                    'source_origin' => FinanceConstants::SOURCE_ORIGIN_SYSTEM,
                    'source_system' => 'INVESTMENT_ENGINE',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            return $this->getPlanById($ownerId, $planId);
        });
    }

    /**
     * @return array{items: list<array<string, mixed>>}
     */
    public function createPlan(User $user, array $payload): array
    {
        $sourceSimulationId = (int) ($payload['sourceSimulationId'] ?? 0);
        if ($sourceSimulationId <= 0) {
            throw new \InvalidArgumentException('sourceSimulationId is required to create an investment plan.');
        }

        $conversionPayload = $payload;
        unset($conversionPayload['sourceSimulationId']);

        return $this->convertSimulationToPlan($user, $sourceSimulationId, $conversionPayload);
    }

    /**
     * @return array{items: list<array<string, mixed>>}
     */
    public function listPlans(User $user): array
    {
        $ownerId = $this->requireOwnerId($user);

        /** @var list<array<string, mixed>> $items */
        $items = $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT
                plan.id,
                plan.label,
                plan.investment_type AS "investmentType",
                plan.start_date AS "startDate",
                plan.contribution_day AS "contributionDay",
                plan.monthly_contribution_brl AS "monthlyContributionBrl",
                plan.effective_monthly_rate AS "effectiveMonthlyRate",
                plan.generate_yield_entries AS "generateYieldEntries",
                plan.yield_mode AS "yieldMode",
                plan.status,
                plan.created_at AS "createdAt",
                plan.updated_at AS "updatedAt",
                bank_account.id AS "bankAccountId",
                bank_account.name AS "bankAccountName",
                category.id AS "categoryId",
                category.name AS "categoryName",
                (
                    SELECT COUNT(*)
                    FROM finance_investment_plan_run run
                    WHERE run.investment_plan_id = plan.id
                ) AS "runsCount"
            FROM finance_investment_plan plan
            LEFT JOIN finance_bank_account bank_account ON bank_account.id = plan.default_bank_account_id
            LEFT JOIN finance_category category ON category.id = plan.category_id
            WHERE plan.owner_id = :ownerId
            ORDER BY plan.created_at DESC
        SQL, [
            'ownerId' => $ownerId,
        ]);

        return ['items' => $items];
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function updatePlan(User $user, int $planId, array $payload): array
    {
        $ownerId = $this->requireOwnerId($user);
        $existingPlan = $this->getPlanById($ownerId, $planId);

        $label = array_key_exists('label', $payload)
            ? FinanceInput::normalizeName((string) $payload['label'])
            : (string) $existingPlan['label'];
        $status = array_key_exists('status', $payload)
            ? strtoupper(trim((string) $payload['status']))
            : (string) $existingPlan['status'];
        if (!in_array($status, ['ACTIVE', 'PAUSED', 'CANCELED', 'FINISHED'], true)) {
            throw new \InvalidArgumentException('The investment plan status is invalid.');
        }

        $contributionDay = array_key_exists('contributionDay', $payload)
            ? (int) $payload['contributionDay']
            : (int) $existingPlan['contributionDay'];
        if ($contributionDay < 1 || $contributionDay > 31) {
            throw new \InvalidArgumentException('The contribution day must be between 1 and 31.');
        }

        $monthlyContributionBrl = array_key_exists('monthlyContributionBrl', $payload)
            ? FinanceInput::normalizeMoney($payload['monthlyContributionBrl'], 'monthlyContributionBrl')
            : (float) $existingPlan['monthlyContributionBrl'];

        $generateYieldEntries = array_key_exists('generateYieldEntries', $payload)
            ? FinanceInput::normalizeBoolean($payload['generateYieldEntries'], false)
            : (bool) $existingPlan['generateYieldEntries'];

        $yieldMode = array_key_exists('yieldMode', $payload)
            ? strtoupper(trim((string) $payload['yieldMode']))
            : (string) $existingPlan['yieldMode'];
        if (!in_array($yieldMode, FinanceConstants::INVESTMENT_YIELD_MODES, true)) {
            throw new \InvalidArgumentException('The investment yield mode is invalid.');
        }

        $defaultBankAccountId = array_key_exists('defaultBankAccountId', $payload)
            ? $this->normalizeOwnedBankAccountId($ownerId, $payload['defaultBankAccountId'])
            : (isset($existingPlan['bankAccountId']) ? (int) $existingPlan['bankAccountId'] : null);
        $categoryId = array_key_exists('categoryId', $payload)
            ? $this->normalizeOwnedCategoryId($ownerId, $payload['categoryId'])
            : (isset($existingPlan['categoryId']) ? (int) $existingPlan['categoryId'] : null);

        $this->connection->update('finance_investment_plan', [
            'label' => $label,
            'contribution_day' => $contributionDay,
            'monthly_contribution_brl' => $monthlyContributionBrl,
            'generate_yield_entries' => FinanceInput::toDatabaseBoolean($generateYieldEntries),
            'yield_mode' => $yieldMode,
            'status' => $status,
            'default_bank_account_id' => $defaultBankAccountId,
            'category_id' => $categoryId,
            'updated_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ], [
            'id' => $planId,
            'owner_id' => $ownerId,
        ]);

        return $this->getPlanById($ownerId, $planId);
    }

    public function runMonthlyPlans(int $monthsAhead = 1): int
    {
        $normalizedMonthsAhead = max(0, min(6, $monthsAhead));
        $generatedCount = 0;

        /** @var list<array<string, mixed>> $plans */
        $plans = $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT
                id,
                owner_id,
                label,
                start_date,
                contribution_day,
                monthly_contribution_brl,
                effective_monthly_rate,
                generate_yield_entries,
                yield_mode,
                default_bank_account_id,
                category_id
            FROM finance_investment_plan
            WHERE status = 'ACTIVE'
        SQL);

        foreach ($plans as $plan) {
            $ownerId = (int) $plan['owner_id'];
            $planId = (int) $plan['id'];
            $startDate = new \DateTimeImmutable((string) $plan['start_date']);

            for ($monthOffset = 0; $monthOffset <= $normalizedMonthsAhead; $monthOffset += 1) {
                $targetMonth = (new \DateTimeImmutable('first day of this month'))->modify(sprintf('+%d months', $monthOffset));
                if ($targetMonth < new \DateTimeImmutable($startDate->format('Y-m-01'))) {
                    continue;
                }

                $competenceMonth = $targetMonth->format('Y-m-01');

                $existingRunId = $this->connection->fetchOne(<<<'SQL'
                    SELECT id
                    FROM finance_investment_plan_run
                    WHERE investment_plan_id = :planId
                      AND competence_month = :competenceMonth
                    LIMIT 1
                SQL, [
                    'planId' => $planId,
                    'competenceMonth' => $competenceMonth,
                ]);

                if ($existingRunId !== false) {
                    continue;
                }

                $dueDate = $this->buildDateInMonth($targetMonth, (int) $plan['contribution_day']);
                $entryStatus = FinanceInput::defaultStatusByDirection(FinanceConstants::DIRECTION_PAYABLE, $dueDate);
                $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

                $this->connection->insert('finance_entry', [
                    'owner_id' => $ownerId,
                    'category_id' => isset($plan['category_id']) ? (int) $plan['category_id'] : null,
                    'bank_account_id' => isset($plan['default_bank_account_id']) ? (int) $plan['default_bank_account_id'] : null,
                    'direction' => FinanceConstants::DIRECTION_PAYABLE,
                    'entry_type' => FinanceConstants::ENTRY_TYPE_INVESTMENT_CONTRIBUTION,
                    'status' => $entryStatus,
                    'title' => sprintf('%s - Monthly contribution', $plan['label']),
                    'description' => 'Generated by investment plan run.',
                    'due_date' => $dueDate->format('Y-m-d'),
                    'competence_month' => $competenceMonth,
                    'expected_amount_brl' => (float) $plan['monthly_contribution_brl'],
                    'settled_amount_brl' => 0,
                    'remaining_amount_brl' => (float) $plan['monthly_contribution_brl'],
                    'input_currency_code' => 'BRL',
                    'input_amount' => (float) $plan['monthly_contribution_brl'],
                    'fx_rate_to_brl' => 1,
                    'fx_rate_date' => $dueDate->format('Y-m-d'),
                    'source_origin' => FinanceConstants::SOURCE_ORIGIN_SYSTEM,
                    'source_system' => 'INVESTMENT_ENGINE',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $contributionEntryId = (int) $this->connection->lastInsertId();
                $yieldEntryId = null;

                $generateYieldEntries = FinanceInput::normalizeBoolean($plan['generate_yield_entries'] ?? false, false);
                $yieldMode = strtoupper(trim((string) ($plan['yield_mode'] ?? 'NONE')));

                if ($generateYieldEntries && $yieldMode === 'ESTIMATED') {
                    $estimatedBaseAmount = (float) $this->connection->fetchOne(<<<'SQL'
                        SELECT COALESCE(SUM(entry.expected_amount_brl), 0)
                        FROM finance_investment_plan_run run
                        INNER JOIN finance_entry entry ON entry.id = run.contribution_entry_id
                          AND entry.deleted_at IS NULL
                        WHERE run.investment_plan_id = :planId
                    SQL, [
                        'planId' => $planId,
                    ]);

                    $estimatedBaseAmount = round($estimatedBaseAmount + (float) $plan['monthly_contribution_brl'], 2);
                    $estimatedYieldAmount = round($estimatedBaseAmount * (float) $plan['effective_monthly_rate'], 2);

                    if ($estimatedYieldAmount > 0.00001) {
                        $yieldStatus = FinanceInput::defaultStatusByDirection(FinanceConstants::DIRECTION_RECEIVABLE, $dueDate);

                        $this->connection->insert('finance_entry', [
                            'owner_id' => $ownerId,
                            'category_id' => isset($plan['category_id']) ? (int) $plan['category_id'] : null,
                            'bank_account_id' => isset($plan['default_bank_account_id']) ? (int) $plan['default_bank_account_id'] : null,
                            'direction' => FinanceConstants::DIRECTION_RECEIVABLE,
                            'entry_type' => FinanceConstants::ENTRY_TYPE_INVESTMENT_YIELD,
                            'status' => $yieldStatus,
                            'title' => sprintf('%s - Estimated yield', $plan['label']),
                            'description' => 'Generated by investment plan run (estimated yield mode).',
                            'due_date' => $dueDate->format('Y-m-d'),
                            'competence_month' => $competenceMonth,
                            'expected_amount_brl' => $estimatedYieldAmount,
                            'settled_amount_brl' => 0,
                            'remaining_amount_brl' => $estimatedYieldAmount,
                            'input_currency_code' => 'BRL',
                            'input_amount' => $estimatedYieldAmount,
                            'fx_rate_to_brl' => 1,
                            'fx_rate_date' => $dueDate->format('Y-m-d'),
                            'source_origin' => FinanceConstants::SOURCE_ORIGIN_SYSTEM,
                            'source_system' => 'INVESTMENT_ENGINE',
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);

                        $yieldEntryId = (int) $this->connection->lastInsertId();
                    }
                }

                $this->connection->insert('finance_investment_plan_run', [
                    'investment_plan_id' => $planId,
                    'contribution_entry_id' => $contributionEntryId,
                    'yield_entry_id' => $yieldEntryId,
                    'competence_month' => $competenceMonth,
                    'run_source' => 'job',
                    'generated_at' => $now,
                ]);

                $runId = (int) $this->connection->lastInsertId();

                $this->connection->update('finance_entry', [
                    'investment_plan_run_id' => $runId,
                    'updated_at' => $now,
                ], [
                    'id' => $contributionEntryId,
                    'owner_id' => $ownerId,
                ]);

                if ($yieldEntryId !== null) {
                    $this->connection->update('finance_entry', [
                        'investment_plan_run_id' => $runId,
                        'updated_at' => $now,
                    ], [
                        'id' => $yieldEntryId,
                        'owner_id' => $ownerId,
                    ]);
                }

                ++$generatedCount;
            }
        }

        return $generatedCount;
    }

    /**
     * @return array<string, mixed>
     */
    private function getSimulationById(int $ownerId, int $simulationId): array
    {
        /** @var array<string, mixed>|false $simulation */
        $simulation = $this->connection->fetchAssociative(<<<'SQL'
            SELECT
                id,
                investment_type AS "investmentType",
                label,
                initial_amount_brl AS "initialAmountBrl",
                monthly_contribution_brl AS "monthlyContributionBrl",
                period_months AS "periodMonths",
                rate_input_type AS "rateInputType",
                rate_value AS "rateValue",
                effective_monthly_rate AS "effectiveMonthlyRate",
                total_invested_brl AS "totalInvestedBrl",
                total_yield_brl AS "totalYieldBrl",
                final_amount_brl AS "finalAmountBrl",
                created_at AS "createdAt"
            FROM finance_investment_simulation
            WHERE owner_id = :ownerId
              AND id = :simulationId
            LIMIT 1
        SQL, [
            'ownerId' => $ownerId,
            'simulationId' => $simulationId,
        ]);

        if (!is_array($simulation)) {
            throw new \InvalidArgumentException('Investment simulation not found.');
        }

        /** @var list<array<string, mixed>> $points */
        $points = $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT
                month_index AS "monthIndex",
                invested_amount_brl AS "investedAmountBrl",
                yield_amount_brl AS "yieldAmountBrl",
                total_amount_brl AS "totalAmountBrl"
            FROM finance_investment_simulation_point
            WHERE simulation_id = :simulationId
            ORDER BY month_index ASC
        SQL, [
            'simulationId' => $simulationId,
        ]);

        $simulation['points'] = $points;

        return $simulation;
    }

    /**
     * @return array<string, mixed>
     */
    private function getPlanById(int $ownerId, int $planId): array
    {
        /** @var array<string, mixed>|false $plan */
        $plan = $this->connection->fetchAssociative(<<<'SQL'
            SELECT
                plan.id,
                plan.source_simulation_id AS "sourceSimulationId",
                plan.label,
                plan.investment_type AS "investmentType",
                plan.start_date AS "startDate",
                plan.contribution_day AS "contributionDay",
                plan.monthly_contribution_brl AS "monthlyContributionBrl",
                plan.effective_monthly_rate AS "effectiveMonthlyRate",
                plan.generate_yield_entries AS "generateYieldEntries",
                plan.yield_mode AS "yieldMode",
                plan.status,
                plan.created_at AS "createdAt",
                plan.updated_at AS "updatedAt",
                plan.default_bank_account_id AS "bankAccountId",
                bank_account.name AS "bankAccountName",
                plan.category_id AS "categoryId",
                category.name AS "categoryName"
            FROM finance_investment_plan plan
            LEFT JOIN finance_bank_account bank_account ON bank_account.id = plan.default_bank_account_id
            LEFT JOIN finance_category category ON category.id = plan.category_id
            WHERE plan.owner_id = :ownerId
              AND plan.id = :planId
            LIMIT 1
        SQL, [
            'ownerId' => $ownerId,
            'planId' => $planId,
        ]);

        if (!is_array($plan)) {
            throw new \InvalidArgumentException('Investment plan not found.');
        }

        return $plan;
    }

    private function buildDateInMonth(\DateTimeImmutable $monthReference, int $dayOfMonth): \DateTimeImmutable
    {
        $firstDayOfMonth = new \DateTimeImmutable($monthReference->format('Y-m-01'));
        $lastDayOfMonth = (int) $firstDayOfMonth->format('t');
        $effectiveDay = min(max(1, $dayOfMonth), $lastDayOfMonth);

        return new \DateTimeImmutable(sprintf('%s-%02d', $firstDayOfMonth->format('Y-m'), $effectiveDay));
    }

    private function normalizeOwnedBankAccountId(int $ownerId, mixed $bankAccountId): ?int
    {
        $normalizedBankAccountId = (int) $bankAccountId;
        if ($normalizedBankAccountId <= 0) {
            return null;
        }

        /** @var array<string, mixed>|false $bankAccount */
        $bankAccount = $this->connection->fetchAssociative(
            'SELECT id, is_active FROM finance_bank_account WHERE owner_id = :ownerId AND id = :bankAccountId LIMIT 1',
            [
                'ownerId' => $ownerId,
                'bankAccountId' => $normalizedBankAccountId,
            ],
        );

        if (!is_array($bankAccount)) {
            throw new \InvalidArgumentException('Bank account not found for current user.');
        }

        if (!(bool) $bankAccount['is_active']) {
            throw new \InvalidArgumentException('Inactive bank accounts cannot be linked to investment plans.');
        }

        return $normalizedBankAccountId;
    }

    private function normalizeOwnedCategoryId(int $ownerId, mixed $categoryId): ?int
    {
        $normalizedCategoryId = (int) $categoryId;
        if ($normalizedCategoryId <= 0) {
            return null;
        }

        $exists = $this->connection->fetchOne(
            'SELECT id FROM finance_category WHERE owner_id = :ownerId AND id = :categoryId LIMIT 1',
            [
                'ownerId' => $ownerId,
                'categoryId' => $normalizedCategoryId,
            ],
        );

        if ($exists === false) {
            throw new \InvalidArgumentException('Category not found for current user.');
        }

        return $normalizedCategoryId;
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
