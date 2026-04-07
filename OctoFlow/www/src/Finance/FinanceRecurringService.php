<?php

namespace App\Finance;

use App\Entity\User;
use Doctrine\DBAL\Connection;

final class FinanceRecurringService
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * @return array{items: list<array<string, mixed>>}
     */
    public function listRules(User $user): array
    {
        $ownerId = $this->requireOwnerId($user);

        /** @var list<array<string, mixed>> $items */
        $items = $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT
                rule.id,
                rule.direction,
                rule.title,
                rule.description,
                rule.amount_brl AS "amountBrl",
                rule.frequency,
                rule.day_of_month AS "dayOfMonth",
                rule.starts_at AS "startsAt",
                rule.ends_at AS "endsAt",
                rule.next_run_date AS "nextRunDate",
                rule.is_active AS "isActive",
                rule.created_at AS "createdAt",
                rule.updated_at AS "updatedAt",
                recurring_type.id AS "recurringTypeId",
                recurring_type.name AS "recurringTypeName",
                category.id AS "categoryId",
                category.name AS "categoryName",
                bank_account.id AS "bankAccountId",
                bank_account.name AS "bankAccountName"
            FROM finance_recurring_rule rule
            INNER JOIN finance_recurring_type recurring_type ON recurring_type.id = rule.recurring_type_id
            LEFT JOIN finance_category category ON category.id = rule.category_id
            LEFT JOIN finance_bank_account bank_account ON bank_account.id = rule.default_bank_account_id
            WHERE rule.owner_id = :ownerId
            ORDER BY rule.is_active DESC, rule.next_run_date ASC NULLS LAST, rule.id DESC
        SQL, ['ownerId' => $ownerId]);

        return ['items' => $items];
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function createRule(User $user, array $payload): array
    {
        $ownerId = $this->requireOwnerId($user);

        $direction = FinanceInput::normalizeDirection($payload['direction'] ?? 'PAYABLE');
        $title = FinanceInput::normalizeName((string) ($payload['title'] ?? ''));
        $description = trim((string) ($payload['description'] ?? ''));
        $amountBrl = FinanceInput::normalizeMoney($payload['amountBrl'] ?? 0, 'amountBrl');
        $frequency = strtoupper(trim((string) ($payload['frequency'] ?? 'MONTHLY')));
        $dayOfMonth = (int) ($payload['dayOfMonth'] ?? 1);
        $startsAt = FinanceInput::normalizeDate($payload['startsAt'] ?? 'today', 'startsAt');
        $endsAt = FinanceInput::normalizeOptionalDate($payload['endsAt'] ?? null);

        if ($title === '') {
            throw new \InvalidArgumentException('The recurring rule title is required.');
        }

        if ($amountBrl <= 0) {
            throw new \InvalidArgumentException('The recurring rule amount must be greater than zero.');
        }

        if ($frequency !== 'MONTHLY') {
            throw new \InvalidArgumentException('Only MONTHLY frequency is supported in this version.');
        }

        if ($dayOfMonth < 1 || $dayOfMonth > 31) {
            throw new \InvalidArgumentException('The day of month must be between 1 and 31.');
        }

        if ($endsAt instanceof \DateTimeImmutable && $endsAt < $startsAt) {
            throw new \InvalidArgumentException('The recurring rule end date cannot be earlier than start date.');
        }

        $recurringTypeId = $this->normalizeOwnedRecurringTypeId($ownerId, $payload['recurringTypeId'] ?? null);
        $categoryId = $this->normalizeOwnedCategoryId($ownerId, $payload['categoryId'] ?? null);
        $defaultBankAccountId = $this->normalizeOwnedBankAccountId($ownerId, $payload['defaultBankAccountId'] ?? null);

        $nextRunDate = $this->buildDateInMonth($startsAt, $dayOfMonth);
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $this->connection->insert('finance_recurring_rule', [
            'owner_id' => $ownerId,
            'recurring_type_id' => $recurringTypeId,
            'category_id' => $categoryId,
            'default_bank_account_id' => $defaultBankAccountId,
            'direction' => $direction,
            'title' => $title,
            'description' => $description !== '' ? $description : null,
            'amount_brl' => $amountBrl,
            'frequency' => 'MONTHLY',
            'day_of_month' => $dayOfMonth,
            'starts_at' => $startsAt->format('Y-m-d'),
            'ends_at' => $endsAt?->format('Y-m-d'),
            'next_run_date' => $nextRunDate->format('Y-m-d'),
            'is_active' => FinanceInput::toDatabaseBoolean(true),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $createdRuleId = (int) $this->connection->lastInsertId();

        return $this->getRuleById($ownerId, $createdRuleId);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function updateRule(User $user, int $ruleId, array $payload): array
    {
        $ownerId = $this->requireOwnerId($user);
        $existingRule = $this->getRuleById($ownerId, $ruleId);

        $direction = array_key_exists('direction', $payload)
            ? FinanceInput::normalizeDirection($payload['direction'])
            : (string) $existingRule['direction'];
        $title = array_key_exists('title', $payload)
            ? FinanceInput::normalizeName((string) $payload['title'])
            : (string) $existingRule['title'];
        $description = array_key_exists('description', $payload)
            ? trim((string) $payload['description'])
            : trim((string) ($existingRule['description'] ?? ''));
        $amountBrl = array_key_exists('amountBrl', $payload)
            ? FinanceInput::normalizeMoney($payload['amountBrl'], 'amountBrl')
            : (float) $existingRule['amountBrl'];
        $dayOfMonth = array_key_exists('dayOfMonth', $payload)
            ? (int) $payload['dayOfMonth']
            : (int) ($existingRule['dayOfMonth'] ?? 1);
        $startsAt = array_key_exists('startsAt', $payload)
            ? FinanceInput::normalizeDate($payload['startsAt'], 'startsAt')
            : FinanceInput::normalizeDate($existingRule['startsAt'], 'startsAt');
        $endsAt = array_key_exists('endsAt', $payload)
            ? FinanceInput::normalizeOptionalDate($payload['endsAt'])
            : FinanceInput::normalizeOptionalDate($existingRule['endsAt'] ?? null);
        $isActive = array_key_exists('isActive', $payload)
            ? FinanceInput::normalizeBoolean($payload['isActive'], true)
            : (bool) $existingRule['isActive'];

        if ($title === '') {
            throw new \InvalidArgumentException('The recurring rule title is required.');
        }

        if ($amountBrl <= 0) {
            throw new \InvalidArgumentException('The recurring rule amount must be greater than zero.');
        }

        if ($dayOfMonth < 1 || $dayOfMonth > 31) {
            throw new \InvalidArgumentException('The day of month must be between 1 and 31.');
        }

        if ($endsAt instanceof \DateTimeImmutable && $endsAt < $startsAt) {
            throw new \InvalidArgumentException('The recurring rule end date cannot be earlier than start date.');
        }

        $recurringTypeId = array_key_exists('recurringTypeId', $payload)
            ? $this->normalizeOwnedRecurringTypeId($ownerId, $payload['recurringTypeId'])
            : (int) $existingRule['recurringTypeId'];
        $categoryId = array_key_exists('categoryId', $payload)
            ? $this->normalizeOwnedCategoryId($ownerId, $payload['categoryId'])
            : (isset($existingRule['categoryId']) ? (int) $existingRule['categoryId'] : null);
        $defaultBankAccountId = array_key_exists('defaultBankAccountId', $payload)
            ? $this->normalizeOwnedBankAccountId($ownerId, $payload['defaultBankAccountId'])
            : (isset($existingRule['bankAccountId']) ? (int) $existingRule['bankAccountId'] : null);

        $nextRunDate = $this->buildDateInMonth(new \DateTimeImmutable('today'), $dayOfMonth);
        if ($startsAt > $nextRunDate) {
            $nextRunDate = $this->buildDateInMonth($startsAt, $dayOfMonth);
        }

        $this->connection->update('finance_recurring_rule', [
            'recurring_type_id' => $recurringTypeId,
            'category_id' => $categoryId,
            'default_bank_account_id' => $defaultBankAccountId,
            'direction' => $direction,
            'title' => $title,
            'description' => $description !== '' ? $description : null,
            'amount_brl' => $amountBrl,
            'day_of_month' => $dayOfMonth,
            'starts_at' => $startsAt->format('Y-m-d'),
            'ends_at' => $endsAt?->format('Y-m-d'),
            'next_run_date' => $nextRunDate->format('Y-m-d'),
            'is_active' => FinanceInput::toDatabaseBoolean($isActive),
            'updated_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ], [
            'id' => $ruleId,
            'owner_id' => $ownerId,
        ]);

        return $this->getRuleById($ownerId, $ruleId);
    }

    /**
     * @return array{deleted: bool, id: int, canceledGeneratedEntries: int}
     */
    public function deleteRule(User $user, int $ruleId): array
    {
        $ownerId = $this->requireOwnerId($user);
        $this->getRuleById($ownerId, $ruleId);

        return $this->connection->transactional(function () use ($ownerId, $ruleId): array {
            /** @var list<array{id: int|string, status: string}> $generatedEntries */
            $generatedEntries = $this->connection->fetchAllAssociative(<<<'SQL'
                SELECT id, status
                FROM finance_entry
                WHERE owner_id = :ownerId
                  AND recurring_rule_id = :ruleId
                  AND deleted_at IS NULL
                  AND source_system = 'RECURRING_ENGINE'
                  AND remaining_amount_brl > 0
            SQL, [
                'ownerId' => $ownerId,
                'ruleId' => $ruleId,
            ]);

            $deletedAt = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

            foreach ($generatedEntries as $generatedEntry) {
                $generatedEntryId = (int) ($generatedEntry['id'] ?? 0);
                if ($generatedEntryId <= 0) {
                    continue;
                }

                $previousStatus = (string) ($generatedEntry['status'] ?? '');

                $this->connection->update('finance_entry', [
                    'status' => 'CANCELED',
                    'settled_amount_brl' => 0,
                    'remaining_amount_brl' => 0,
                    'fully_settled_at' => null,
                    'updated_at' => $deletedAt,
                    'deleted_at' => $deletedAt,
                ], [
                    'id' => $generatedEntryId,
                    'owner_id' => $ownerId,
                ]);

                $this->connection->insert('finance_entry_status_history', [
                    'owner_id' => $ownerId,
                    'entry_id' => $generatedEntryId,
                    'from_status' => $previousStatus !== '' ? $previousStatus : null,
                    'to_status' => 'CANCELED',
                    'reason_code' => 'RECURRING_RULE_DELETED',
                    'reason_text' => 'Recurring rule deleted by user.',
                    'changed_at' => $deletedAt,
                ]);
            }

            $this->connection->delete('finance_recurring_rule', [
                'id' => $ruleId,
                'owner_id' => $ownerId,
            ]);

            return [
                'deleted' => true,
                'id' => $ruleId,
                'canceledGeneratedEntries' => count($generatedEntries),
            ];
        });
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function generateRuleEntryManually(User $user, int $ruleId, array $payload = []): array
    {
        $ownerId = $this->requireOwnerId($user);
        $rule = $this->getRuleGenerationDataById($ownerId, $ruleId);

        if (!(bool) ($rule['isActive'] ?? false)) {
            throw new \InvalidArgumentException('Inactive recurring rules cannot generate entries.');
        }

        $requestedCompetenceDate = FinanceInput::normalizeOptionalDate($payload['competenceMonth'] ?? null);
        $ruleNextRunDate = FinanceInput::normalizeOptionalDate($rule['nextRunDate'] ?? null);
        $referenceDate = $requestedCompetenceDate ?? $ruleNextRunDate ?? new \DateTimeImmutable('today');
        $targetCompetenceMonth = new \DateTimeImmutable($referenceDate->format('Y-m-01'));

        $generatedCount = $this->generateMissingEntriesForRule(
            $ownerId,
            $rule,
            $targetCompetenceMonth,
            $targetCompetenceMonth,
            'manual',
            true,
        );

        return [
            'generated' => $generatedCount > 0,
            'generatedCount' => $generatedCount,
            'competenceMonth' => $targetCompetenceMonth->format('Y-m-01'),
            'entry' => $this->findActiveGeneratedEntryByRuleAndCompetence($ownerId, $ruleId, $targetCompetenceMonth),
            'rule' => $this->getRuleById($ownerId, $ruleId),
        ];
    }

    public function generateMissingEntriesForRange(User $user, \DateTimeImmutable $startDate, \DateTimeImmutable $endDate, string $runSource = 'lazy'): int
    {
        $ownerId = $this->requireOwnerId($user);

        /** @var list<array<string, mixed>> $rules */
        $rules = $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT
                id,
                direction,
                title,
                amount_brl AS "amountBrl",
                day_of_month AS "dayOfMonth",
                starts_at AS "startsAt",
                ends_at AS "endsAt",
                category_id AS "categoryId",
                default_bank_account_id AS "defaultBankAccountId"
            FROM finance_recurring_rule
            WHERE owner_id = :ownerId
              AND is_active = true
        SQL, ['ownerId' => $ownerId]);

        $generatedCount = 0;
        $startMonth = new \DateTimeImmutable($startDate->format('Y-m-01'));
        $endMonth = new \DateTimeImmutable($endDate->format('Y-m-01'));

        foreach ($rules as $rule) {
            $generatedCount += $this->generateMissingEntriesForRule($ownerId, $rule, $startMonth, $endMonth, $runSource);
        }

        return $generatedCount;
    }

    public function generateDailySync(int $monthsAhead = 2): int
    {
        $monthsToGenerate = max(1, min(12, $monthsAhead));
        $startMonth = new \DateTimeImmutable('first day of this month');
        $endMonth = $startMonth->modify(sprintf('+%d months', $monthsToGenerate));

        /** @var list<array{owner_id: int}> $owners */
        $owners = $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT DISTINCT owner_id
            FROM finance_recurring_rule
            WHERE is_active = true
        SQL);

        $generatedCount = 0;

        foreach ($owners as $ownerRow) {
            $ownerId = (int) ($ownerRow['owner_id'] ?? 0);
            if ($ownerId <= 0) {
                continue;
            }

            /** @var list<array<string, mixed>> $rules */
            $rules = $this->connection->fetchAllAssociative(<<<'SQL'
                SELECT
                    id,
                    direction,
                    title,
                    amount_brl AS "amountBrl",
                    day_of_month AS "dayOfMonth",
                    starts_at AS "startsAt",
                    ends_at AS "endsAt",
                    category_id AS "categoryId",
                    default_bank_account_id AS "defaultBankAccountId"
                FROM finance_recurring_rule
                WHERE owner_id = :ownerId
                  AND is_active = true
            SQL, ['ownerId' => $ownerId]);

            foreach ($rules as $rule) {
                $generatedCount += $this->generateMissingEntriesForRule($ownerId, $rule, $startMonth, $endMonth, 'job');
            }
        }

        return $generatedCount;
    }

    /**
     * @param array<string, mixed> $rule
     */
    private function generateMissingEntriesForRule(
        int $ownerId,
        array $rule,
        \DateTimeImmutable $startMonth,
        \DateTimeImmutable $endMonth,
        string $runSource,
        bool $forceGeneration = false,
    ): int {
        $ruleId = (int) $rule['id'];
        $dayOfMonth = (int) ($rule['dayOfMonth'] ?? 1);
        $ruleStartDate = new \DateTimeImmutable((string) $rule['startsAt']);
        $ruleEndDate = !empty($rule['endsAt']) ? new \DateTimeImmutable((string) $rule['endsAt']) : null;
        $ruleStartCompetenceMonth = $ruleStartDate->format('Y-m-01');
        $today = new \DateTimeImmutable('today');

        $generatedCount = 0;

        foreach ($this->iterateMonths($startMonth, $endMonth) as $month) {
            $competenceMonth = $month->format('Y-m-01');
            $dueDate = $this->buildDateInMonth($month, $dayOfMonth);
            if ($dueDate < $ruleStartDate) {
                continue;
            }

            if ($ruleEndDate instanceof \DateTimeImmutable && $dueDate > $ruleEndDate) {
                continue;
            }

            // Gera a próxima competência apenas depois do vencimento da competência anterior.
            // A regra segue o vencimento, mesmo se o lançamento anterior ainda estiver em aberto.
            if (!$forceGeneration && $competenceMonth !== $ruleStartCompetenceMonth) {
                $previousMonth = $month->modify('-1 month');
                $previousDueDate = $this->buildDateInMonth($previousMonth, $dayOfMonth);
                if ($previousDueDate > $today) {
                    continue;
                }
            }

            /** @var array<string, mixed>|false $existingRun */
            $existingRun = $this->connection->fetchAssociative(<<<'SQL'
                SELECT
                    run.id AS "runId",
                    run.generated_entry_id AS "generatedEntryId",
                    entry.deleted_at AS "generatedEntryDeletedAt"
                FROM finance_recurring_rule_run run
                LEFT JOIN finance_entry entry ON entry.id = run.generated_entry_id
                WHERE run.recurring_rule_id = :ruleId
                  AND run.competence_month = :competenceMonth
                LIMIT 1
            SQL, [
                'ruleId' => $ruleId,
                'competenceMonth' => $competenceMonth,
            ]);

            $existingRunId = null;
            if (is_array($existingRun)) {
                $existingRunId = (int) ($existingRun['runId'] ?? 0);
                $generatedEntryId = (int) ($existingRun['generatedEntryId'] ?? 0);
                $generatedEntryDeletedAt = $existingRun['generatedEntryDeletedAt'] ?? null;

                // Já existe lançamento ativo para a competência: mantém idempotência.
                if ($generatedEntryId > 0 && $generatedEntryDeletedAt === null) {
                    continue;
                }
            }

            $entryStatus = FinanceInput::defaultStatusByDirection(
                (string) $rule['direction'],
                $dueDate,
            );

            $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

            $this->connection->insert('finance_entry', [
                'owner_id' => $ownerId,
                'category_id' => isset($rule['categoryId']) ? (int) $rule['categoryId'] : null,
                'bank_account_id' => isset($rule['defaultBankAccountId']) ? (int) $rule['defaultBankAccountId'] : null,
                'recurring_rule_id' => $ruleId,
                'direction' => (string) $rule['direction'],
                'entry_type' => FinanceConstants::ENTRY_TYPE_RECURRING,
                'status' => $entryStatus,
                'title' => (string) $rule['title'],
                'description' => 'Generated by recurring rule.',
                'due_date' => $dueDate->format('Y-m-d'),
                'competence_month' => $competenceMonth,
                'expected_amount_brl' => round((float) $rule['amountBrl'], 2),
                'settled_amount_brl' => 0,
                'remaining_amount_brl' => round((float) $rule['amountBrl'], 2),
                'input_currency_code' => 'BRL',
                'input_amount' => round((float) $rule['amountBrl'], 2),
                'fx_rate_to_brl' => 1,
                'fx_rate_date' => $dueDate->format('Y-m-d'),
                'source_origin' => FinanceConstants::SOURCE_ORIGIN_SYSTEM,
                'source_system' => 'RECURRING_ENGINE',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $generatedEntryId = (int) $this->connection->lastInsertId();

            if ($existingRunId !== null && $existingRunId > 0) {
                $this->connection->update('finance_recurring_rule_run', [
                    'generated_entry_id' => $generatedEntryId,
                    'run_source' => $runSource,
                    'generated_at' => $now,
                ], [
                    'id' => $existingRunId,
                    'recurring_rule_id' => $ruleId,
                ]);
            } else {
                $this->connection->insert('finance_recurring_rule_run', [
                    'recurring_rule_id' => $ruleId,
                    'generated_entry_id' => $generatedEntryId,
                    'competence_month' => $competenceMonth,
                    'run_source' => $runSource,
                    'generated_at' => $now,
                ]);
            }

            $this->connection->insert('finance_entry_status_history', [
                'owner_id' => $ownerId,
                'entry_id' => $generatedEntryId,
                'from_status' => null,
                'to_status' => $entryStatus,
                'reason_code' => 'RECURRING_GENERATED',
                'reason_text' => null,
                'changed_at' => $now,
            ]);

            ++$generatedCount;
        }

        return $generatedCount;
    }

    /**
     * @return array<string, mixed>
     */
    private function getRuleGenerationDataById(int $ownerId, int $ruleId): array
    {
        /** @var array<string, mixed>|false $result */
        $result = $this->connection->fetchAssociative(<<<'SQL'
            SELECT
                id,
                direction,
                title,
                amount_brl AS "amountBrl",
                day_of_month AS "dayOfMonth",
                starts_at AS "startsAt",
                ends_at AS "endsAt",
                category_id AS "categoryId",
                default_bank_account_id AS "defaultBankAccountId",
                next_run_date AS "nextRunDate",
                is_active AS "isActive"
            FROM finance_recurring_rule
            WHERE owner_id = :ownerId
              AND id = :ruleId
            LIMIT 1
        SQL, [
            'ownerId' => $ownerId,
            'ruleId' => $ruleId,
        ]);

        if (!is_array($result)) {
            throw new \InvalidArgumentException('Recurring rule not found.');
        }

        return $result;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findActiveGeneratedEntryByRuleAndCompetence(
        int $ownerId,
        int $ruleId,
        \DateTimeImmutable $competenceMonth,
    ): ?array {
        /** @var array<string, mixed>|false $entry */
        $entry = $this->connection->fetchAssociative(<<<'SQL'
            SELECT
                id,
                direction,
                entry_type AS "entryType",
                status,
                title,
                due_date AS "dueDate",
                competence_month AS "competenceMonth",
                expected_amount_brl AS "expectedAmountBrl",
                remaining_amount_brl AS "remainingAmountBrl",
                created_at AS "createdAt",
                updated_at AS "updatedAt"
            FROM finance_entry
            WHERE owner_id = :ownerId
              AND recurring_rule_id = :ruleId
              AND source_system = 'RECURRING_ENGINE'
              AND competence_month = :competenceMonth
              AND deleted_at IS NULL
            ORDER BY id DESC
            LIMIT 1
        SQL, [
            'ownerId' => $ownerId,
            'ruleId' => $ruleId,
            'competenceMonth' => $competenceMonth->format('Y-m-01'),
        ]);

        if (!is_array($entry)) {
            return null;
        }

        return $entry;
    }

    /**
     * @return array<string, mixed>
     */
    private function getRuleById(int $ownerId, int $ruleId): array
    {
        /** @var array<string, mixed>|false $result */
        $result = $this->connection->fetchAssociative(<<<'SQL'
            SELECT
                rule.id,
                rule.direction,
                rule.title,
                rule.description,
                rule.amount_brl AS "amountBrl",
                rule.frequency,
                rule.day_of_month AS "dayOfMonth",
                rule.starts_at AS "startsAt",
                rule.ends_at AS "endsAt",
                rule.next_run_date AS "nextRunDate",
                rule.is_active AS "isActive",
                rule.created_at AS "createdAt",
                rule.updated_at AS "updatedAt",
                rule.recurring_type_id AS "recurringTypeId",
                recurring_type.name AS "recurringTypeName",
                rule.category_id AS "categoryId",
                category.name AS "categoryName",
                rule.default_bank_account_id AS "bankAccountId",
                bank_account.name AS "bankAccountName"
            FROM finance_recurring_rule rule
            INNER JOIN finance_recurring_type recurring_type ON recurring_type.id = rule.recurring_type_id
            LEFT JOIN finance_category category ON category.id = rule.category_id
            LEFT JOIN finance_bank_account bank_account ON bank_account.id = rule.default_bank_account_id
            WHERE rule.owner_id = :ownerId
              AND rule.id = :ruleId
            LIMIT 1
        SQL, [
            'ownerId' => $ownerId,
            'ruleId' => $ruleId,
        ]);

        if (!is_array($result)) {
            throw new \InvalidArgumentException('Recurring rule not found.');
        }

        return $result;
    }

    /**
     * @return \Generator<int, \DateTimeImmutable>
     */
    private function iterateMonths(\DateTimeImmutable $startMonth, \DateTimeImmutable $endMonth): \Generator
    {
        $cursor = $startMonth;

        while ($cursor <= $endMonth) {
            yield $cursor;
            $cursor = $cursor->modify('+1 month');
        }
    }

    private function buildDateInMonth(\DateTimeImmutable $monthReference, int $dayOfMonth): \DateTimeImmutable
    {
        $firstDayOfMonth = new \DateTimeImmutable($monthReference->format('Y-m-01'));
        $lastDayOfMonth = (int) $firstDayOfMonth->format('t');
        $effectiveDay = min(max(1, $dayOfMonth), $lastDayOfMonth);

        return new \DateTimeImmutable(sprintf('%s-%02d', $firstDayOfMonth->format('Y-m'), $effectiveDay));
    }

    private function normalizeOwnedRecurringTypeId(int $ownerId, mixed $recurringTypeId): int
    {
        $normalizedRecurringTypeId = (int) $recurringTypeId;
        if ($normalizedRecurringTypeId <= 0) {
            throw new \InvalidArgumentException('The recurring type is required.');
        }

        $exists = $this->connection->fetchOne(
            'SELECT id FROM finance_recurring_type WHERE owner_id = :ownerId AND id = :recurringTypeId LIMIT 1',
            [
                'ownerId' => $ownerId,
                'recurringTypeId' => $normalizedRecurringTypeId,
            ],
        );

        if ($exists === false) {
            throw new \InvalidArgumentException('Recurring type not found for current user.');
        }

        return $normalizedRecurringTypeId;
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
            throw new \InvalidArgumentException('Inactive bank accounts cannot be linked to recurring rules.');
        }

        return $normalizedBankAccountId;
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
