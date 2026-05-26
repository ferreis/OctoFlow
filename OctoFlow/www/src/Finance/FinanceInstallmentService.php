<?php

namespace App\Finance;

use App\Entity\User;
use Doctrine\DBAL\Connection;

final class FinanceInstallmentService
{
    public function __construct(
        private readonly Connection $connection,
        private readonly FinanceEntryService $financeEntryService,
    ) {
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
                plan.direction,
                plan.title,
                plan.total_amount_brl AS "totalAmountBrl",
                plan.down_payment_brl AS "downPaymentBrl",
                plan.installment_amount_brl AS "installmentAmountBrl",
                plan.installments_count AS "installmentsCount",
                plan.interest_amount_brl AS "interestAmountBrl",
                plan.discount_amount_brl AS "discountAmountBrl",
                plan.fine_amount_brl AS "fineAmountBrl",
                plan.first_due_date AS "firstDueDate",
                plan.status,
                plan.created_at AS "createdAt",
                plan.updated_at AS "updatedAt",
                category.id AS "categoryId",
                category.name AS "categoryName",
                bank_account.id AS "bankAccountId",
                bank_account.name AS "bankAccountName",
                (
                    SELECT COUNT(*)
                    FROM finance_installment_item item
                    WHERE item.plan_id = plan.id
                ) AS "itemsCount",
                (
                    SELECT COUNT(*)
                    FROM finance_installment_item item
                    INNER JOIN finance_entry entry ON entry.id = item.entry_id
                    WHERE item.plan_id = plan.id
                      AND entry.deleted_at IS NULL
                      AND entry.remaining_amount_brl <= 0
                ) AS "paidItemsCount",
                (
                    SELECT COALESCE(SUM(entry.remaining_amount_brl), 0)
                    FROM finance_installment_item item
                    INNER JOIN finance_entry entry ON entry.id = item.entry_id
                    WHERE item.plan_id = plan.id
                      AND entry.deleted_at IS NULL
                ) AS "remainingAmountBrl"
            FROM finance_installment_plan plan
            LEFT JOIN finance_category category ON category.id = plan.category_id
            LEFT JOIN finance_bank_account bank_account ON bank_account.id = plan.default_bank_account_id
            WHERE plan.owner_id = :ownerId
              AND plan.deleted_at IS NULL
            ORDER BY plan.created_at DESC, plan.id DESC
        SQL, ['ownerId' => $ownerId]);

        return ['items' => $items];
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function createPlan(User $user, array $payload): array
    {
        $ownerId = $this->requireOwnerId($user);

        return $this->createPlanByOwnerId($ownerId, $payload);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function updatePlan(User $user, int $planId, array $payload): array
    {
        $ownerId = $this->requireOwnerId($user);

        $requestedStatus = strtoupper(trim((string) ($payload['status'] ?? '')));
        if ($requestedStatus === 'CANCELED') {
            return $this->softDeletePlan($user, $planId);
        }

        return $this->connection->transactional(function () use ($user, $ownerId, $planId, $payload): array {
            $existingPlan = $this->getPlanById($ownerId, $planId);

            $title = FinanceInput::normalizeName((string) ($payload['title'] ?? $existingPlan['title'] ?? ''));
            $totalAmountBrl = FinanceInput::normalizeMoney($payload['totalAmountBrl'] ?? $existingPlan['totalAmountBrl'] ?? 0, 'totalAmountBrl');
            $downPaymentBrl = FinanceInput::normalizeOptionalMoney($payload['downPaymentBrl'] ?? $existingPlan['downPaymentBrl'] ?? null, 0.0) ?? 0.0;
            $interestAmountBrl = FinanceInput::normalizeOptionalMoney($payload['interestAmountBrl'] ?? $existingPlan['interestAmountBrl'] ?? null, 0.0) ?? 0.0;
            $discountAmountBrl = FinanceInput::normalizeOptionalMoney($payload['discountAmountBrl'] ?? $existingPlan['discountAmountBrl'] ?? null, 0.0) ?? 0.0;
            $fineAmountBrl = FinanceInput::normalizeOptionalMoney($payload['fineAmountBrl'] ?? $existingPlan['fineAmountBrl'] ?? null, 0.0) ?? 0.0;
            $installmentsCount = (int) ($payload['installmentsCount'] ?? $existingPlan['installmentsCount'] ?? 0);
            $firstDueDate = FinanceInput::normalizeDate($payload['firstDueDate'] ?? $existingPlan['firstDueDate'] ?? 'today', 'firstDueDate');
            $categoryId = $this->normalizeOwnedCategoryId($ownerId, $payload['categoryId'] ?? $existingPlan['categoryId'] ?? null);
            $defaultBankAccountId = $this->normalizeOwnedBankAccountId($ownerId, $payload['defaultBankAccountId'] ?? $existingPlan['bankAccountId'] ?? null);

            if ($title === '') {
                throw new \InvalidArgumentException('The installment plan title is required.');
            }

            if ($totalAmountBrl <= 0) {
                throw new \InvalidArgumentException('The total amount must be greater than zero.');
            }

            if ($installmentsCount <= 0) {
                throw new \InvalidArgumentException('The installments count must be greater than zero.');
            }

            $calculatedNetAmount = round($totalAmountBrl + $interestAmountBrl + $fineAmountBrl - $discountAmountBrl - $downPaymentBrl, 2);
            if ($calculatedNetAmount <= 0) {
                throw new \InvalidArgumentException('The calculated net amount must be greater than zero.');
            }

            $installmentAmountBrl = round($calculatedNetAmount / $installmentsCount, 2);

            $this->softDeleteInstallmentEntriesForPlan($user, $planId);
            $this->softDeleteDownPaymentEntriesForPlan($user, $ownerId, $existingPlan);
            $this->deleteInstallmentItemsForPlan($planId);

            $this->connection->update('finance_installment_plan', [
                'category_id' => $categoryId,
                'default_bank_account_id' => $defaultBankAccountId,
                'title' => $title,
                'total_amount_brl' => $totalAmountBrl,
                'down_payment_brl' => $downPaymentBrl,
                'installment_amount_brl' => $installmentAmountBrl,
                'installments_count' => $installmentsCount,
                'interest_amount_brl' => $interestAmountBrl,
                'discount_amount_brl' => $discountAmountBrl,
                'fine_amount_brl' => $fineAmountBrl,
                'first_due_date' => $firstDueDate->format('Y-m-d'),
                'status' => 'ACTIVE',
                'updated_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s')
            ], [
                'id' => $planId,
                'owner_id' => $ownerId,
            ]);

            $this->generateInstallmentItemsAndEntries(
                $ownerId,
                $planId,
                (string) ($existingPlan['direction'] ?? FinanceConstants::DIRECTION_PAYABLE),
                $title,
                $categoryId,
                $defaultBankAccountId,
                $installmentsCount,
                $installmentAmountBrl,
                $calculatedNetAmount,
                $firstDueDate,
            );

            if ($downPaymentBrl > 0.00001) {
                $this->createDownPaymentEntry(
                    $ownerId,
                    (string) ($existingPlan['direction'] ?? FinanceConstants::DIRECTION_PAYABLE),
                    $title,
                    $categoryId,
                    $defaultBankAccountId,
                    $downPaymentBrl,
                    $firstDueDate,
                );
            }

            return $this->getPlanById($ownerId, $planId);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function softDeletePlan(User $user, int $planId): array
    {
        $ownerId = $this->requireOwnerId($user);

        return $this->connection->transactional(function () use ($user, $ownerId, $planId): array {
            $plan = $this->getPlanById($ownerId, $planId);

            $this->softDeleteInstallmentEntriesForPlan($user, $planId);
            $this->softDeleteDownPaymentEntriesForPlan($user, $ownerId, $plan);

            $deletedAt = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

            $this->connection->update('finance_installment_plan', [
                'status' => 'CANCELED',
                'deleted_at' => $deletedAt,
                'updated_at' => $deletedAt,
            ], [
                'id' => $planId,
                'owner_id' => $ownerId,
            ]);

            return [
                'id' => $planId,
                'status' => 'CANCELED',
                'deletedAt' => $deletedAt,
            ];
        });
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function applyPlanAdjustment(User $user, int $planId, array $payload): array
    {
        $ownerId = $this->requireOwnerId($user);

        $adjustmentType = strtoupper(trim((string) ($payload['adjustmentType'] ?? '')));
        if (!in_array($adjustmentType, ['ADVANCE', 'DISCOUNT'], true)) {
            throw new \InvalidArgumentException('Tipo de ajuste inválido para o parcelamento.');
        }

        $adjustmentAmountBrl = FinanceInput::normalizeMoney($payload['amountBrl'] ?? 0, 'amountBrl');
        if ($adjustmentAmountBrl <= 0) {
            throw new \InvalidArgumentException('O valor do ajuste deve ser maior que zero.');
        }

        $currentPlan = $this->getPlanById($ownerId, $planId);
        $currentTotalAmountBrl = (float) ($currentPlan['totalAmountBrl'] ?? 0);
        if ($adjustmentAmountBrl >= $currentTotalAmountBrl) {
            throw new \InvalidArgumentException('O ajuste deve ser menor que o valor total do parcelamento.');
        }

        return $this->updatePlan($user, $planId, [
            'title' => $currentPlan['title'] ?? '',
            'totalAmountBrl' => round($currentTotalAmountBrl - $adjustmentAmountBrl, 2),
            'downPaymentBrl' => $currentPlan['downPaymentBrl'] ?? 0,
            'installmentsCount' => $currentPlan['installmentsCount'] ?? 1,
            'interestAmountBrl' => $currentPlan['interestAmountBrl'] ?? 0,
            'discountAmountBrl' => $currentPlan['discountAmountBrl'] ?? 0,
            'fineAmountBrl' => $currentPlan['fineAmountBrl'] ?? 0,
            'firstDueDate' => $currentPlan['firstDueDate'] ?? 'today',
            'categoryId' => $currentPlan['categoryId'] ?? null,
            'defaultBankAccountId' => $currentPlan['bankAccountId'] ?? null,
        ]);
    }

    private function softDeleteInstallmentEntriesForPlan(User $user, int $planId): void
    {
        /** @var list<int|string> $installmentEntryIds */
        $installmentEntryIds = $this->connection->fetchFirstColumn(<<<'SQL'
            SELECT entry.id
            FROM finance_installment_item item
            INNER JOIN finance_entry entry ON entry.id = item.entry_id
            WHERE item.plan_id = :planId
              AND entry.deleted_at IS NULL
        SQL, [
            'planId' => $planId,
        ]);

        foreach ($installmentEntryIds as $entryIdValue) {
            $entryId = (int) $entryIdValue;
            if ($entryId <= 0) {
                continue;
            }

            $this->financeEntryService->softDeleteEntry($user, $entryId);
        }
    }

    private function deleteInstallmentItemsForPlan(int $planId): void
    {
        $this->connection->delete('finance_installment_item', [
            'plan_id' => $planId,
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function renegotiatePlan(User $user, int $planId, array $payload): array
    {
        $ownerId = $this->requireOwnerId($user);

        return $this->connection->transactional(function () use ($ownerId, $planId, $payload): array {
            $originalPlan = $this->getPlanById($ownerId, $planId);

            /** @var list<array<string, mixed>> $openItems */
            $openItems = $this->connection->fetchAllAssociative(<<<'SQL'
                SELECT
                    item.id AS "itemId",
                    entry.id AS "entryId",
                    entry.remaining_amount_brl AS "remainingAmountBrl"
                FROM finance_installment_item item
                INNER JOIN finance_entry entry ON entry.id = item.entry_id
                WHERE item.plan_id = :planId
                  AND entry.deleted_at IS NULL
                  AND entry.remaining_amount_brl > 0
                  AND entry.status NOT IN ('CANCELED', 'NEGOTIATED')
            SQL, [
                'planId' => $planId,
            ]);

            $remainingAmountBrl = 0.0;
            foreach ($openItems as $openItem) {
                $remainingAmountBrl += (float) $openItem['remainingAmountBrl'];
            }
            $remainingAmountBrl = round($remainingAmountBrl, 2);

            if ($remainingAmountBrl <= 0.00001) {
                throw new \InvalidArgumentException('This installment plan has no remaining open balance.');
            }

            foreach ($openItems as $openItem) {
                $entryId = (int) $openItem['entryId'];
                $entryStatus = ((string) $originalPlan['direction']) === FinanceConstants::DIRECTION_PAYABLE
                    ? 'NEGOTIATED'
                    : 'CANCELED';

                $this->connection->update('finance_entry', [
                    'status' => $entryStatus,
                    'updated_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                ], [
                    'id' => $entryId,
                    'owner_id' => $ownerId,
                ]);

                $this->connection->insert('finance_entry_status_history', [
                    'owner_id' => $ownerId,
                    'entry_id' => $entryId,
                    'from_status' => null,
                    'to_status' => $entryStatus,
                    'reason_code' => 'PLAN_RENEGOTIATED',
                    'reason_text' => 'Automatically updated during installment renegotiation.',
                    'changed_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                ]);
            }

            $this->connection->update('finance_installment_plan', [
                'status' => 'RENEGOTIATED',
                'updated_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ], [
                'id' => $planId,
                'owner_id' => $ownerId,
            ]);

            $newPlanPayload = [
                'direction' => $originalPlan['direction'],
                'title' => (string) ($payload['title'] ?? sprintf('%s (Renegotiated)', $originalPlan['title'])),
                'totalAmountBrl' => FinanceInput::normalizeOptionalMoney($payload['totalAmountBrl'] ?? null, $remainingAmountBrl),
                'downPaymentBrl' => FinanceInput::normalizeOptionalMoney($payload['downPaymentBrl'] ?? null, 0.0),
                'installmentAmountBrl' => $payload['installmentAmountBrl'] ?? null,
                'installmentsCount' => (int) ($payload['installmentsCount'] ?? 1),
                'interestAmountBrl' => FinanceInput::normalizeOptionalMoney($payload['interestAmountBrl'] ?? null, 0.0),
                'discountAmountBrl' => FinanceInput::normalizeOptionalMoney($payload['discountAmountBrl'] ?? null, 0.0),
                'fineAmountBrl' => FinanceInput::normalizeOptionalMoney($payload['fineAmountBrl'] ?? null, 0.0),
                'firstDueDate' => $payload['firstDueDate'] ?? (new \DateTimeImmutable('today'))->format('Y-m-d'),
                'categoryId' => $payload['categoryId'] ?? $originalPlan['categoryId'] ?? null,
                'defaultBankAccountId' => $payload['defaultBankAccountId'] ?? $originalPlan['bankAccountId'] ?? null,
            ];

            $newPlan = $this->createPlanByOwnerId($ownerId, $newPlanPayload);
            $newPlanId = (int) $newPlan['id'];

            $this->connection->insert('finance_negotiation', [
                'owner_id' => $ownerId,
                'original_entry_id' => null,
                'original_plan_id' => $planId,
                'new_plan_id' => $newPlanId,
                'reason' => trim((string) ($payload['reason'] ?? 'Installment renegotiation')),
                'discount_amount_brl' => (float) ($newPlanPayload['discountAmountBrl'] ?? 0),
                'fine_amount_brl' => (float) ($newPlanPayload['fineAmountBrl'] ?? 0),
                'interest_amount_brl' => (float) ($newPlanPayload['interestAmountBrl'] ?? 0),
                'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ]);

            return [
                'originalPlan' => $this->getPlanById($ownerId, $planId),
                'newPlan' => $this->getPlanById($ownerId, $newPlanId),
                'remainingAmountBrl' => $remainingAmountBrl,
            ];
        });
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function createPlanByOwnerId(int $ownerId, array $payload): array
    {
        return $this->connection->transactional(function () use ($ownerId, $payload): array {
            $direction = FinanceInput::normalizeDirection($payload['direction'] ?? FinanceConstants::DIRECTION_PAYABLE);
            $title = FinanceInput::normalizeName((string) ($payload['title'] ?? ''));
            $totalAmountBrl = FinanceInput::normalizeMoney($payload['totalAmountBrl'] ?? 0, 'totalAmountBrl');
            $downPaymentBrl = FinanceInput::normalizeOptionalMoney($payload['downPaymentBrl'] ?? null, 0.0) ?? 0.0;
            $interestAmountBrl = FinanceInput::normalizeOptionalMoney($payload['interestAmountBrl'] ?? null, 0.0) ?? 0.0;
            $discountAmountBrl = FinanceInput::normalizeOptionalMoney($payload['discountAmountBrl'] ?? null, 0.0) ?? 0.0;
            $fineAmountBrl = FinanceInput::normalizeOptionalMoney($payload['fineAmountBrl'] ?? null, 0.0) ?? 0.0;
            $installmentsCount = (int) ($payload['installmentsCount'] ?? 0);
            $firstDueDate = FinanceInput::normalizeDate($payload['firstDueDate'] ?? 'today', 'firstDueDate');

            if ($title === '') {
                throw new \InvalidArgumentException('The installment plan title is required.');
            }

            if ($totalAmountBrl <= 0) {
                throw new \InvalidArgumentException('The total amount must be greater than zero.');
            }

            if ($installmentsCount <= 0) {
                throw new \InvalidArgumentException('The installments count must be greater than zero.');
            }

            $calculatedNetAmount = round($totalAmountBrl + $interestAmountBrl + $fineAmountBrl - $discountAmountBrl - $downPaymentBrl, 2);
            if ($calculatedNetAmount <= 0) {
                throw new \InvalidArgumentException('The calculated net amount must be greater than zero.');
            }

            $providedInstallmentAmount = FinanceInput::normalizeOptionalMoney($payload['installmentAmountBrl'] ?? null, null);
            $installmentAmountBrl = $providedInstallmentAmount !== null && $providedInstallmentAmount > 0
                ? round($providedInstallmentAmount, 2)
                : round($calculatedNetAmount / $installmentsCount, 2);

            $categoryId = $this->normalizeOwnedCategoryId($ownerId, $payload['categoryId'] ?? null);
            $defaultBankAccountId = $this->normalizeOwnedBankAccountId($ownerId, $payload['defaultBankAccountId'] ?? null);

            $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
            $this->connection->insert('finance_installment_plan', [
                'owner_id' => $ownerId,
                'category_id' => $categoryId,
                'default_bank_account_id' => $defaultBankAccountId,
                'direction' => $direction,
                'title' => $title,
                'total_amount_brl' => $totalAmountBrl,
                'down_payment_brl' => $downPaymentBrl,
                'installment_amount_brl' => $installmentAmountBrl,
                'installments_count' => $installmentsCount,
                'interest_amount_brl' => $interestAmountBrl,
                'discount_amount_brl' => $discountAmountBrl,
                'fine_amount_brl' => $fineAmountBrl,
                'first_due_date' => $firstDueDate->format('Y-m-d'),
                'status' => 'ACTIVE',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $planId = (int) $this->connection->lastInsertId();

            $this->generateInstallmentItemsAndEntries(
                $ownerId,
                $planId,
                $direction,
                $title,
                $categoryId,
                $defaultBankAccountId,
                $installmentsCount,
                $installmentAmountBrl,
                $calculatedNetAmount,
                $firstDueDate,
            );

            if ($downPaymentBrl > 0.00001) {
                $this->createDownPaymentEntry(
                    $ownerId,
                    $direction,
                    $title,
                    $categoryId,
                    $defaultBankAccountId,
                    $downPaymentBrl,
                    $firstDueDate,
                );
            }

            return $this->getPlanById($ownerId, $planId);
        });
    }

    private function createDownPaymentEntry(
        int $ownerId,
        string $direction,
        string $title,
        ?int $categoryId,
        ?int $defaultBankAccountId,
        float $downPaymentBrl,
        \DateTimeImmutable $firstDueDate,
    ): void {
        $entryStatus = FinanceInput::defaultStatusByDirection($direction, $firstDueDate);
        $entryNow = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $this->connection->insert('finance_entry', [
            'owner_id' => $ownerId,
            'category_id' => $categoryId,
            'bank_account_id' => $defaultBankAccountId,
            'direction' => $direction,
            'entry_type' => FinanceConstants::ENTRY_TYPE_ONE_OFF,
            'status' => $entryStatus,
            'title' => sprintf('%s (Down Payment)', $title),
            'description' => 'Auto generated down payment from installment plan.',
            'due_date' => $firstDueDate->format('Y-m-d'),
            'competence_month' => $firstDueDate->format('Y-m-01'),
            'expected_amount_brl' => $downPaymentBrl,
            'settled_amount_brl' => 0,
            'remaining_amount_brl' => $downPaymentBrl,
            'input_currency_code' => 'BRL',
            'input_amount' => $downPaymentBrl,
            'fx_rate_to_brl' => 1,
            'fx_rate_date' => $firstDueDate->format('Y-m-d'),
            'source_origin' => FinanceConstants::SOURCE_ORIGIN_SYSTEM,
            'source_system' => 'INSTALLMENT_ENGINE',
            'created_at' => $entryNow,
            'updated_at' => $entryNow,
        ]);
    }

    private function generateInstallmentItemsAndEntries(
        int $ownerId,
        int $planId,
        string $direction,
        string $title,
        ?int $categoryId,
        ?int $defaultBankAccountId,
        int $installmentsCount,
        float $installmentAmountBrl,
        float $calculatedNetAmount,
        \DateTimeImmutable $firstDueDate,
    ): void {
        $generatedAmountAccumulator = 0.0;

        for ($installmentIndex = 1; $installmentIndex <= $installmentsCount; $installmentIndex += 1) {
            $dueDate = $firstDueDate->modify(sprintf('+%d months', $installmentIndex - 1));
            $expectedInstallmentAmount = $installmentIndex === $installmentsCount
                ? round($calculatedNetAmount - $generatedAmountAccumulator, 2)
                : $installmentAmountBrl;
            $generatedAmountAccumulator = round($generatedAmountAccumulator + $expectedInstallmentAmount, 2);

            $entryStatus = FinanceInput::defaultStatusByDirection($direction, $dueDate);
            $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

            $this->connection->insert('finance_entry', [
                'owner_id' => $ownerId,
                'category_id' => $categoryId,
                'bank_account_id' => $defaultBankAccountId,
                'direction' => $direction,
                'entry_type' => FinanceConstants::ENTRY_TYPE_INSTALLMENT,
                'status' => $entryStatus,
                'title' => sprintf('%s - Installment %d/%d', $title, $installmentIndex, $installmentsCount),
                'description' => sprintf('Installment %d generated by installment plan %d.', $installmentIndex, $planId),
                'due_date' => $dueDate->format('Y-m-d'),
                'competence_month' => $dueDate->format('Y-m-01'),
                'expected_amount_brl' => $expectedInstallmentAmount,
                'settled_amount_brl' => 0,
                'remaining_amount_brl' => $expectedInstallmentAmount,
                'input_currency_code' => 'BRL',
                'input_amount' => $expectedInstallmentAmount,
                'fx_rate_to_brl' => 1,
                'fx_rate_date' => $dueDate->format('Y-m-d'),
                'source_origin' => FinanceConstants::SOURCE_ORIGIN_SYSTEM,
                'source_system' => 'INSTALLMENT_ENGINE',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $createdEntryId = (int) $this->connection->lastInsertId();

            $this->connection->insert('finance_installment_item', [
                'plan_id' => $planId,
                'entry_id' => $createdEntryId,
                'installment_number' => $installmentIndex,
                'due_date' => $dueDate->format('Y-m-d'),
                'expected_amount_brl' => $expectedInstallmentAmount,
                'created_at' => $now,
            ]);

            $createdInstallmentItemId = (int) $this->connection->lastInsertId();

            $this->connection->update('finance_entry', [
                'installment_item_id' => $createdInstallmentItemId,
                'updated_at' => $now,
            ], [
                'id' => $createdEntryId,
                'owner_id' => $ownerId,
            ]);
        }
    }

    /**
     * @param array<string, mixed> $plan
     */
    private function softDeleteDownPaymentEntriesForPlan(User $user, int $ownerId, array $plan): void
    {
        $downPaymentBrl = (float) ($plan['downPaymentBrl'] ?? 0);
        if ($downPaymentBrl <= 0.00001) {
            return;
        }

        $downPaymentTitle = sprintf('%s (Down Payment)', (string) ($plan['title'] ?? ''));
        $firstDueDate = (string) ($plan['firstDueDate'] ?? '');
        $direction = (string) ($plan['direction'] ?? '');
        $categoryId = isset($plan['categoryId']) ? (int) $plan['categoryId'] : null;
        $bankAccountId = isset($plan['bankAccountId']) ? (int) $plan['bankAccountId'] : null;
        if ($downPaymentTitle === ' (Down Payment)' || $firstDueDate === '') {
            return;
        }

        $categoryFilterSql = 'AND entry.category_id IS NULL';
        $bankAccountFilterSql = 'AND entry.bank_account_id IS NULL';
        $queryParameters = [
            'ownerId' => $ownerId,
            'direction' => $direction,
            'title' => $downPaymentTitle,
            'dueDate' => $firstDueDate,
            'amountBrl' => $downPaymentBrl,
        ];

        if ($categoryId !== null && $categoryId > 0) {
            $categoryFilterSql = 'AND entry.category_id = :categoryId';
            $queryParameters['categoryId'] = $categoryId;
        }

        if ($bankAccountId !== null && $bankAccountId > 0) {
            $bankAccountFilterSql = 'AND entry.bank_account_id = :bankAccountId';
            $queryParameters['bankAccountId'] = $bankAccountId;
        }

        $downPaymentEntryQuery = sprintf(
            <<<'SQL'
            SELECT entry.id
            FROM finance_entry entry
            WHERE entry.owner_id = :ownerId
              AND entry.deleted_at IS NULL
              AND entry.installment_item_id IS NULL
              AND entry.direction = :direction
              %s
              %s
              AND entry.source_system = 'INSTALLMENT_ENGINE'
              AND entry.source_origin = 'SYSTEM'
              AND entry.entry_type = 'ONE_OFF'
              AND entry.title = :title
              AND entry.due_date = :dueDate
              AND entry.expected_amount_brl = :amountBrl
            SQL,
            $categoryFilterSql,
            $bankAccountFilterSql,
        );

        /** @var list<int|string> $downPaymentEntryIds */
        $downPaymentEntryIds = $this->connection->fetchFirstColumn($downPaymentEntryQuery, $queryParameters);

        foreach ($downPaymentEntryIds as $entryIdValue) {
            $entryId = (int) $entryIdValue;
            if ($entryId <= 0) {
                continue;
            }

            $this->financeEntryService->softDeleteEntry($user, $entryId);
        }
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
                plan.direction,
                plan.title,
                plan.total_amount_brl AS "totalAmountBrl",
                plan.down_payment_brl AS "downPaymentBrl",
                plan.installment_amount_brl AS "installmentAmountBrl",
                plan.installments_count AS "installmentsCount",
                plan.interest_amount_brl AS "interestAmountBrl",
                plan.discount_amount_brl AS "discountAmountBrl",
                plan.fine_amount_brl AS "fineAmountBrl",
                plan.first_due_date AS "firstDueDate",
                plan.status,
                plan.created_at AS "createdAt",
                plan.updated_at AS "updatedAt",
                plan.category_id AS "categoryId",
                category.name AS "categoryName",
                plan.default_bank_account_id AS "bankAccountId",
                bank_account.name AS "bankAccountName",
                (
                    SELECT COALESCE(SUM(entry.remaining_amount_brl), 0)
                    FROM finance_installment_item item
                    INNER JOIN finance_entry entry ON entry.id = item.entry_id
                    WHERE item.plan_id = plan.id
                      AND entry.deleted_at IS NULL
                ) AS "remainingAmountBrl"
            FROM finance_installment_plan plan
            LEFT JOIN finance_category category ON category.id = plan.category_id
            LEFT JOIN finance_bank_account bank_account ON bank_account.id = plan.default_bank_account_id
            WHERE plan.owner_id = :ownerId
              AND plan.id = :planId
              AND plan.deleted_at IS NULL
            LIMIT 1
        SQL, [
            'ownerId' => $ownerId,
            'planId' => $planId,
        ]);

        if (!is_array($plan)) {
            throw new \InvalidArgumentException('Installment plan not found.');
        }

        return $plan;
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
            throw new \InvalidArgumentException('Inactive bank accounts cannot be linked to installment plans.');
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
