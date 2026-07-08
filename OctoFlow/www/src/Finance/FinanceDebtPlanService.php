<?php

namespace App\Finance;

use App\Entity\User;
use Doctrine\DBAL\Connection;

final class FinanceDebtPlanService
{
    private const DEFAULT_MAX_COMMITMENT_PERCENT = 30.0;
    private const MIN_MAX_COMMITMENT_PERCENT = 5.0;
    private const MAX_MAX_COMMITMENT_PERCENT = 90.0;

    public function __construct(
        private readonly Connection $connection,
        private readonly FinanceInstallmentService $financeInstallmentService,
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
                debt_plan.id,
                debt_plan.title,
                debt_plan.creditor_name AS "creditorName",
                debt_plan.total_amount_brl AS "totalAmountBrl",
                debt_plan.negotiated_amount_brl AS "negotiatedAmountBrl",
                debt_plan.proposed_amount_brl AS "proposedAmountBrl",
                debt_plan.selected_reference_amount_brl AS "selectedReferenceAmountBrl",
                debt_plan.selected_reference_type AS "selectedReferenceType",
                debt_plan.down_payment_brl AS "downPaymentBrl",
                debt_plan.planned_total_amount_brl AS "plannedTotalAmountBrl",
                debt_plan.monthly_income_brl AS "monthlyIncomeBrl",
                debt_plan.max_commitment_percent AS "maxCommitmentPercent",
                debt_plan.max_recommended_payment_brl AS "maxRecommendedPaymentBrl",
                debt_plan.settlement_mode AS "settlementMode",
                debt_plan.selected_installments_count AS "selectedInstallmentsCount",
                debt_plan.selected_monthly_payment_brl AS "selectedMonthlyPaymentBrl",
                debt_plan.status,
                debt_plan.negotiation_note AS "negotiationNote",
                debt_plan.proposal_note AS "proposalNote",
                debt_plan.notes,
                debt_plan.created_at AS "createdAt",
                debt_plan.updated_at AS "updatedAt",
                category.id AS "categoryId",
                category.name AS "categoryName",
                bank_account.id AS "bankAccountId",
                bank_account.name AS "bankAccountName",
                installment_plan.id AS "installmentPlanId",
                installment_plan.title AS "installmentPlanTitle",
                full_entry.id AS "fullPaymentEntryId",
                full_entry.title AS "fullPaymentEntryTitle"
            FROM finance_debt_plan debt_plan
            LEFT JOIN finance_category category ON category.id = debt_plan.category_id
            LEFT JOIN finance_bank_account bank_account ON bank_account.id = debt_plan.default_bank_account_id
            LEFT JOIN finance_installment_plan installment_plan ON installment_plan.id = debt_plan.linked_installment_plan_id
              AND installment_plan.deleted_at IS NULL
            LEFT JOIN finance_entry full_entry ON full_entry.id = debt_plan.full_payment_entry_id
              AND full_entry.deleted_at IS NULL
            WHERE debt_plan.owner_id = :ownerId
              AND debt_plan.deleted_at IS NULL
            ORDER BY debt_plan.created_at DESC, debt_plan.id DESC
        SQL, [
            'ownerId' => $ownerId,
        ]);

        return [
            'items' => $items,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function previewPlan(User $user, array $payload): array
    {
        $ownerId = $this->requireOwnerId($user);

        return $this->buildPreviewByOwnerId($ownerId, $payload);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function createPlan(User $user, array $payload): array
    {
        $ownerId = $this->requireOwnerId($user);
        $preview = $this->buildPreviewByOwnerId($ownerId, $payload);
        $selectedSuggestion = $this->resolveSelectedSuggestion($payload, $preview);

        if ($selectedSuggestion !== null) {
            $selectedSettlementMode = $this->normalizeSettlementMode($selectedSuggestion['settlementMode'] ?? null);
            $selectedInstallmentsCount = max(1, (int) ($selectedSuggestion['installmentsCount'] ?? 1));
            $selectedMonthlyPaymentBrl = $this->roundMoney((float) ($selectedSuggestion['monthlyPaymentBrl'] ?? 0));
        } else {
            $selectedSettlementMode = $this->normalizeSettlementMode($payload['settlementMode'] ?? null);
            $selectedInstallmentsCount = $this->resolveSelectedInstallmentsCount($selectedSettlementMode, $payload, $preview);
            $selectedMonthlyPaymentBrl = $this->roundMoney($preview['plannedTotalAmountBrl'] / $selectedInstallmentsCount);
        }

        if ($selectedMonthlyPaymentBrl <= 0) {
            throw new \InvalidArgumentException('A opção selecionada para o plano de dívida é inválida.');
        }

        $maxRecommendedPaymentBrl = (float) $preview['maxRecommendedPaymentBrl'];
        if ($maxRecommendedPaymentBrl > 0 && $selectedMonthlyPaymentBrl > ($maxRecommendedPaymentBrl + 0.01)) {
            throw new \InvalidArgumentException('A opção selecionada ultrapassa o limite recomendado para sua renda disponível.');
        }

        if ($maxRecommendedPaymentBrl > 0 && $selectedSuggestion !== null && ($selectedSuggestion['withinLimit'] ?? false) !== true) {
            throw new \InvalidArgumentException('A opção selecionada está fora do limite permitido. Refaça a simulação e selecione uma opção válida.');
        }

        $categoryId = $this->normalizeOwnedCategoryId($ownerId, $payload['categoryId'] ?? null);
        $defaultBankAccountId = $this->normalizeOwnedBankAccountId($ownerId, $payload['defaultBankAccountId'] ?? null);

        $title = FinanceInput::normalizeName((string) ($payload['title'] ?? 'Plano de dívida'));
        if ($title === '') {
            throw new \InvalidArgumentException('O título do plano de dívida é obrigatório.');
        }

        $creditorName = trim((string) ($payload['creditorName'] ?? ''));
        $firstDueDate = FinanceInput::normalizeDate($payload['firstDueDate'] ?? 'today', 'firstDueDate')->format('Y-m-d');
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $createdDebtPlanId = $this->connection->transactional(function () use (
            $ownerId,
            $user,
            $preview,
            $selectedSettlementMode,
            $selectedInstallmentsCount,
            $selectedMonthlyPaymentBrl,
            $categoryId,
            $defaultBankAccountId,
            $title,
            $creditorName,
            $firstDueDate,
            $now,
        ): int {
            $this->connection->insert('finance_debt_plan', [
                'owner_id' => $ownerId,
                'category_id' => $categoryId,
                'default_bank_account_id' => $defaultBankAccountId,
                'linked_installment_plan_id' => null,
                'full_payment_entry_id' => null,
                'title' => $title,
                'creditor_name' => $creditorName !== '' ? $creditorName : null,
                'notes' => null,
                'total_amount_brl' => (float) $preview['totalAmountBrl'],
                'negotiated_amount_brl' => $preview['negotiatedAmountBrl'] !== null ? (float) $preview['negotiatedAmountBrl'] : null,
                'proposed_amount_brl' => $preview['proposedAmountBrl'] !== null ? (float) $preview['proposedAmountBrl'] : null,
                'selected_reference_amount_brl' => (float) $preview['selectedReferenceAmountBrl'],
                'selected_reference_type' => (string) $preview['selectedReferenceType'],
                'down_payment_brl' => (float) $preview['downPaymentBrl'],
                'planned_total_amount_brl' => (float) $preview['plannedTotalAmountBrl'],
                'monthly_income_brl' => (float) $preview['monthlyIncomeBrl'],
                'max_commitment_percent' => (float) $preview['maxCommitmentPercent'],
                'max_recommended_payment_brl' => (float) $preview['maxRecommendedPaymentBrl'],
                'settlement_mode' => $selectedSettlementMode,
                'selected_installments_count' => $selectedInstallmentsCount,
                'selected_monthly_payment_brl' => $selectedMonthlyPaymentBrl,
                'status' => 'ACTIVE',
                'negotiation_note' => null,
                'proposal_note' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $debtPlanId = (int) $this->connection->lastInsertId();

            if ($selectedSettlementMode === 'FULL') {
                $fullPaymentEntry = $this->financeEntryService->createEntry(
                    $user,
                    [
                        'direction' => FinanceConstants::DIRECTION_PAYABLE,
                        'entryType' => 'DEBT',
                        'title' => sprintf('%s - Quitação à vista', $title),
                        'description' => 'Lançamento gerado pelo planejador de dívida.',
                        'dueDate' => $firstDueDate,
                        'expectedAmountBrl' => (float) $preview['plannedTotalAmountBrl'],
                        'categoryId' => $categoryId,
                        'bankAccountId' => $defaultBankAccountId,
                        'sourceOrigin' => FinanceConstants::SOURCE_ORIGIN_MANUAL,
                        'sourceSystem' => 'DEBT_PLANNER',
                    ],
                    false,
                    false,
                );

                $this->connection->update('finance_debt_plan', [
                    'full_payment_entry_id' => (int) $fullPaymentEntry['id'],
                    'updated_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                ], [
                    'id' => $debtPlanId,
                    'owner_id' => $ownerId,
                ]);

                return $debtPlanId;
            }

            $createdInstallmentPlan = $this->financeInstallmentService->createPlan(
                $user,
                [
                    'direction' => FinanceConstants::DIRECTION_PAYABLE,
                    'title' => sprintf('%s - Parcelamento', $title),
                    'totalAmountBrl' => (float) $preview['plannedTotalAmountBrl'],
                    'downPaymentBrl' => 0,
                    'installmentAmountBrl' => $selectedMonthlyPaymentBrl,
                    'installmentsCount' => $selectedInstallmentsCount,
                    'interestAmountBrl' => 0,
                    'discountAmountBrl' => 0,
                    'fineAmountBrl' => 0,
                    'firstDueDate' => $firstDueDate,
                    'categoryId' => $categoryId,
                    'defaultBankAccountId' => $defaultBankAccountId,
                ],
                false,
                false,
            );

            $this->connection->update('finance_debt_plan', [
                'linked_installment_plan_id' => (int) $createdInstallmentPlan['id'],
                'updated_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ], [
                'id' => $debtPlanId,
                'owner_id' => $ownerId,
            ]);

            return $debtPlanId;
        });

        return [
            'item' => $this->getPlanById($ownerId, $createdDebtPlanId),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function deletePlan(User $user, int $debtPlanId): array
    {
        $ownerId = $this->requireOwnerId($user);

        return $this->connection->transactional(function () use ($user, $ownerId, $debtPlanId): array {
            $debtPlan = $this->getPlanById($ownerId, $debtPlanId);

            $linkedInstallmentPlanId = (int) ($debtPlan['installmentPlanId'] ?? 0);
            if ($linkedInstallmentPlanId > 0) {
                $this->financeInstallmentService->softDeletePlan($user, $linkedInstallmentPlanId);
            }

            $fullPaymentEntryId = (int) ($debtPlan['fullPaymentEntryId'] ?? 0);
            if ($fullPaymentEntryId > 0) {
                $this->financeEntryService->softDeleteEntry($user, $fullPaymentEntryId);
            }

            $deletedAt = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

            $this->connection->update('finance_debt_plan', [
                'status' => 'CANCELED',
                'deleted_at' => $deletedAt,
                'updated_at' => $deletedAt,
            ], [
                'id' => $debtPlanId,
                'owner_id' => $ownerId,
            ]);

            return [
                'id' => $debtPlanId,
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
    private function buildPreviewByOwnerId(int $ownerId, array $payload): array
    {
        $totalAmountBrl = FinanceInput::normalizeMoney($payload['totalAmountBrl'] ?? 0, 'totalAmountBrl');

        $negotiatedAmountBrl = FinanceInput::normalizeOptionalMoney($payload['negotiatedAmountBrl'] ?? null, null);
        if ($negotiatedAmountBrl !== null && $negotiatedAmountBrl <= 0) {
            $negotiatedAmountBrl = null;
        }

        $proposedAmountBrl = FinanceInput::normalizeOptionalMoney($payload['proposedAmountBrl'] ?? null, null);
        if ($proposedAmountBrl !== null && $proposedAmountBrl <= 0) {
            $proposedAmountBrl = null;
        }

        $discountAmountBrl = FinanceInput::normalizeOptionalMoney($payload['discountAmountBrl'] ?? null, null);
        if ($discountAmountBrl !== null && $discountAmountBrl <= 0) {
            $discountAmountBrl = null;
        }

        if ($discountAmountBrl !== null && $discountAmountBrl >= $totalAmountBrl) {
            throw new \InvalidArgumentException('O desconto deve ser menor que o valor total da dívida.');
        }

        if ($negotiatedAmountBrl === null && $proposedAmountBrl === null && $discountAmountBrl !== null) {
            $negotiatedAmountBrl = $this->roundMoney($totalAmountBrl - $discountAmountBrl);
        }

        $referenceAmountBrl = $totalAmountBrl;
        $referenceType = 'FULL';
        if ($negotiatedAmountBrl !== null && $negotiatedAmountBrl > 0) {
            $referenceAmountBrl = $negotiatedAmountBrl;
            $referenceType = 'NEGOTIATED';
        } elseif ($proposedAmountBrl !== null && $proposedAmountBrl > 0) {
            $referenceAmountBrl = $proposedAmountBrl;
            $referenceType = 'PROPOSED';
        }

        $downPaymentBrl = FinanceInput::normalizeOptionalMoney($payload['downPaymentBrl'] ?? null, 0.0) ?? 0.0;
        if ($downPaymentBrl < 0) {
            throw new \InvalidArgumentException('A entrada não pode ser negativa.');
        }

        if ($downPaymentBrl >= $referenceAmountBrl) {
            throw new \InvalidArgumentException('A entrada deve ser menor que o valor de referência da dívida.');
        }

        $plannedTotalAmountBrl = $this->roundMoney($referenceAmountBrl - $downPaymentBrl);
        if ($plannedTotalAmountBrl <= 0) {
            throw new \InvalidArgumentException('O valor planejado deve ser maior que zero.');
        }

        $monthlyBudgetContext = $this->estimateMonthlyBudgetContext($ownerId);
        $estimatedMonthlyIncomeBrl = (float) $monthlyBudgetContext['monthlyReceivablesBrl'];
        $estimatedMonthlyPayablesBrl = (float) $monthlyBudgetContext['monthlyPayablesBrl'];
        $estimatedExistingDebtCommitmentBrl = (float) $monthlyBudgetContext['monthlyDebtCommitmentBrl'];
        $effectiveMonthlyIncomeBrl = $estimatedMonthlyIncomeBrl;

        $maxCommitmentPercent = $this->normalizeMaxCommitmentPercent($payload['maxCommitmentPercent'] ?? null);
        $incomeCommitmentLimitBrl = $effectiveMonthlyIncomeBrl > 0
            ? $this->roundMoney(($effectiveMonthlyIncomeBrl * $maxCommitmentPercent) / 100)
            : 0.0;
        $monthlyDisposableIncomeBrl = max(0.0, $this->roundMoney($effectiveMonthlyIncomeBrl - $estimatedMonthlyPayablesBrl));
        $monthlyBudgetAfterDebtBrl = max(0.0, $this->roundMoney($monthlyDisposableIncomeBrl - $estimatedExistingDebtCommitmentBrl));
        $maxRecommendedPaymentBrl = $this->roundMoney(min($incomeCommitmentLimitBrl, $monthlyBudgetAfterDebtBrl));

        $desiredInstallmentsCount = (int) ($payload['desiredInstallmentsCount'] ?? 0);
        $suggestions = $this->buildSuggestions(
            $plannedTotalAmountBrl,
            $effectiveMonthlyIncomeBrl,
            $maxCommitmentPercent,
            $maxRecommendedPaymentBrl,
            $desiredInstallmentsCount,
        );

        $recommendedOption = $this->resolveRecommendedOption($suggestions);

        return [
            'totalAmountBrl' => $this->roundMoney($totalAmountBrl),
            'negotiatedAmountBrl' => $negotiatedAmountBrl !== null ? $this->roundMoney($negotiatedAmountBrl) : null,
            'proposedAmountBrl' => $proposedAmountBrl !== null ? $this->roundMoney($proposedAmountBrl) : null,
            'discountAmountBrl' => $discountAmountBrl !== null ? $this->roundMoney($discountAmountBrl) : null,
            'selectedReferenceAmountBrl' => $this->roundMoney($referenceAmountBrl),
            'selectedReferenceType' => $referenceType,
            'downPaymentBrl' => $this->roundMoney($downPaymentBrl),
            'plannedTotalAmountBrl' => $plannedTotalAmountBrl,
            'monthlyIncomeBrl' => $this->roundMoney($effectiveMonthlyIncomeBrl),
            'monthlyIncomeSource' => 'SYSTEM_RECEIVABLES',
            'estimatedMonthlyReceivablesBrl' => $this->roundMoney($estimatedMonthlyIncomeBrl),
            'estimatedMonthlyPayablesBrl' => $this->roundMoney($estimatedMonthlyPayablesBrl),
            'existingDebtCommitmentBrl' => $this->roundMoney($estimatedExistingDebtCommitmentBrl),
            'monthlyDisposableIncomeBrl' => $this->roundMoney($monthlyDisposableIncomeBrl),
            'maxCommitmentPercent' => $maxCommitmentPercent,
            'incomeCommitmentLimitBrl' => $incomeCommitmentLimitBrl,
            'maxRecommendedPaymentBrl' => $maxRecommendedPaymentBrl,
            'suggestions' => $suggestions,
            'recommendedOption' => $recommendedOption,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildSuggestions(
        float $plannedTotalAmountBrl,
        float $monthlyIncomeBrl,
        float $maxCommitmentPercent,
        float $maxRecommendedPaymentBrl,
        int $desiredInstallmentsCount,
    ): array {
        $suggestions = [];

        $fullCommitmentPercent = $monthlyIncomeBrl > 0
            ? $this->roundMoney(($plannedTotalAmountBrl / $monthlyIncomeBrl) * 100)
            : null;

        $suggestions[] = [
            'key' => 'FULL',
            'label' => 'Pagamento à vista',
            'settlementMode' => 'FULL',
            'installmentsCount' => 1,
            'monthlyPaymentBrl' => $this->roundMoney($plannedTotalAmountBrl),
            'commitmentPercent' => $fullCommitmentPercent,
            'withinLimit' => $maxRecommendedPaymentBrl > 0 ? $plannedTotalAmountBrl <= $maxRecommendedPaymentBrl : false,
        ];

        $installmentCandidates = [];
        if ($maxRecommendedPaymentBrl > 0) {
            $minimumInstallmentsForLimit = max(2, (int) ceil($plannedTotalAmountBrl / $maxRecommendedPaymentBrl));
            $installmentCandidates[] = $minimumInstallmentsForLimit;
            $installmentCandidates[] = $minimumInstallmentsForLimit + 6;

            $comfortableCommitmentPercent = min(30.0, $maxCommitmentPercent);
            $comfortableMonthlyLimitBrl = $this->roundMoney(($monthlyIncomeBrl * $comfortableCommitmentPercent) / 100);
            if ($comfortableMonthlyLimitBrl > 0) {
                $installmentCandidates[] = max(2, (int) ceil($plannedTotalAmountBrl / $comfortableMonthlyLimitBrl));
            }
        }

        if ($desiredInstallmentsCount > 1) {
            $installmentCandidates[] = $desiredInstallmentsCount;
        }

        $installmentCandidates[] = 12;
        $installmentCandidates = array_values(array_unique(array_filter(
            $installmentCandidates,
            static fn (int $installmentsCount): bool => $installmentsCount > 1 && $installmentsCount <= 120,
        )));
        sort($installmentCandidates);

        foreach ($installmentCandidates as $installmentsCount) {
            $monthlyPaymentBrl = $this->roundMoney($plannedTotalAmountBrl / $installmentsCount);
            $commitmentPercent = $monthlyIncomeBrl > 0
                ? $this->roundMoney(($monthlyPaymentBrl / $monthlyIncomeBrl) * 100)
                : null;

            $label = sprintf('%dx no valor de %s', $installmentsCount, number_format($monthlyPaymentBrl, 2, ',', '.'));
            if ($maxRecommendedPaymentBrl > 0 && $monthlyPaymentBrl <= $maxRecommendedPaymentBrl) {
                $label = sprintf('%s (dentro do limite)', $label);
            }

            $suggestions[] = [
                'key' => sprintf('INSTALLMENT_%d', $installmentsCount),
                'label' => $label,
                'settlementMode' => 'INSTALLMENT',
                'installmentsCount' => $installmentsCount,
                'monthlyPaymentBrl' => $monthlyPaymentBrl,
                'commitmentPercent' => $commitmentPercent,
                'withinLimit' => $maxRecommendedPaymentBrl > 0 ? $monthlyPaymentBrl <= ($maxRecommendedPaymentBrl + 0.01) : false,
            ];
        }

        return $suggestions;
    }

    /**
     * @param list<array<string, mixed>> $suggestions
     *
     * @return array<string, mixed>|null
     */
    private function resolveRecommendedOption(array $suggestions): ?array
    {
        foreach ($suggestions as $suggestion) {
            if (($suggestion['settlementMode'] ?? null) !== 'INSTALLMENT') {
                continue;
            }

            if (($suggestion['withinLimit'] ?? false) === true) {
                return $suggestion;
            }
        }

        foreach ($suggestions as $suggestion) {
            if (($suggestion['settlementMode'] ?? null) === 'INSTALLMENT') {
                return $suggestion;
            }
        }

        return $suggestions[0] ?? null;
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $preview
     */
    private function resolveSelectedInstallmentsCount(string $selectedSettlementMode, array $payload, array $preview): int
    {
        if ($selectedSettlementMode === 'FULL') {
            return 1;
        }

        $providedInstallmentsCount = (int) ($payload['selectedInstallmentsCount'] ?? 0);
        if ($providedInstallmentsCount > 1) {
            return $providedInstallmentsCount;
        }

        $recommendedOption = is_array($preview['recommendedOption'] ?? null)
            ? $preview['recommendedOption']
            : null;
        $recommendedInstallmentsCount = (int) ($recommendedOption['installmentsCount'] ?? 0);
        if ($recommendedInstallmentsCount > 1) {
            return $recommendedInstallmentsCount;
        }

        return 12;
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $preview
     *
     * @return array<string, mixed>|null
     */
    private function resolveSelectedSuggestion(array $payload, array $preview): ?array
    {
        $previewSuggestions = is_array($preview['suggestions'] ?? null)
            ? $preview['suggestions']
            : [];

        if ($previewSuggestions === []) {
            return null;
        }

        $selectedSuggestionKey = trim((string) ($payload['selectedSuggestionKey'] ?? ''));
        if ($selectedSuggestionKey !== '') {
            foreach ($previewSuggestions as $suggestionOption) {
                if (!is_array($suggestionOption)) {
                    continue;
                }

                if ((string) ($suggestionOption['key'] ?? '') === $selectedSuggestionKey) {
                    return $suggestionOption;
                }
            }

            throw new \InvalidArgumentException('A opção selecionada não corresponde ao resultado da simulação. Refaça a simulação.');
        }

        $selectedSettlementMode = $this->normalizeSettlementMode($payload['settlementMode'] ?? null);
        $selectedInstallmentsCount = (int) ($payload['selectedInstallmentsCount'] ?? $payload['desiredInstallmentsCount'] ?? 0);
        if ($selectedSettlementMode === 'FULL') {
            $selectedInstallmentsCount = 1;
        }

        if ($selectedInstallmentsCount <= 0) {
            return null;
        }

        foreach ($previewSuggestions as $suggestionOption) {
            if (!is_array($suggestionOption)) {
                continue;
            }

            $suggestionSettlementMode = $this->normalizeSettlementMode($suggestionOption['settlementMode'] ?? null);
            $suggestionInstallmentsCount = (int) ($suggestionOption['installmentsCount'] ?? 0);
            if ($suggestionSettlementMode !== $selectedSettlementMode) {
                continue;
            }

            if ($suggestionInstallmentsCount !== $selectedInstallmentsCount) {
                continue;
            }

            return $suggestionOption;
        }

        return null;
    }

    private function normalizeSettlementMode(mixed $settlementMode): string
    {
        $normalizedSettlementMode = strtoupper(trim((string) $settlementMode));
        if ($normalizedSettlementMode === '') {
            return 'INSTALLMENT';
        }

        if (!in_array($normalizedSettlementMode, ['INSTALLMENT', 'FULL'], true)) {
            throw new \InvalidArgumentException('Modo de pagamento inválido para o plano de dívida.');
        }

        return $normalizedSettlementMode;
    }

    private function normalizeMaxCommitmentPercent(mixed $maxCommitmentPercent): float
    {
        if (!is_numeric($maxCommitmentPercent)) {
            return self::DEFAULT_MAX_COMMITMENT_PERCENT;
        }

        $normalizedPercent = (float) $maxCommitmentPercent;

        if ($normalizedPercent < self::MIN_MAX_COMMITMENT_PERCENT) {
            return self::MIN_MAX_COMMITMENT_PERCENT;
        }

        if ($normalizedPercent > self::MAX_MAX_COMMITMENT_PERCENT) {
            return self::MAX_MAX_COMMITMENT_PERCENT;
        }

        return $this->roundMoney($normalizedPercent);
    }

    /**
     * @return array{monthlyReceivablesBrl: float, monthlyPayablesBrl: float, monthlyDebtCommitmentBrl: float}
     */
    private function estimateMonthlyBudgetContext(int $ownerId): array
    {
        $initialCompetenceMonth = (new \DateTimeImmutable('first day of -2 months'))->format('Y-m-01');

        /** @var array<string, mixed>|false $budgetContext */
        $budgetContext = $this->connection->fetchAssociative(<<<'SQL'
            SELECT
                COALESCE(AVG(monthly_receivables_brl), 0) AS avg_receivables_brl,
                COALESCE(AVG(monthly_payables_brl), 0) AS avg_payables_brl,
                COALESCE(AVG(monthly_debt_commitment_brl), 0) AS avg_debt_commitment_brl
            FROM (
                SELECT
                    DATE_TRUNC('month', COALESCE(entry.competence_month, entry.due_date)) AS reference_month,
                    COALESCE(SUM(CASE
                        WHEN entry.direction = 'RECEIVABLE'
                         AND entry.status <> 'CANCELED'
                        THEN entry.expected_amount_brl
                        ELSE 0
                    END), 0) AS monthly_receivables_brl,
                    COALESCE(SUM(CASE
                        WHEN entry.direction = 'PAYABLE'
                         AND entry.entry_type <> 'DEBT'
                         AND entry.status <> 'CANCELED'
                        THEN entry.expected_amount_brl
                        ELSE 0
                    END), 0) AS monthly_payables_brl,
                    COALESCE(SUM(CASE
                        WHEN entry.direction = 'PAYABLE'
                         AND entry.entry_type = 'DEBT'
                         AND entry.remaining_amount_brl > 0
                         AND entry.status NOT IN ('PAID', 'CANCELED')
                        THEN entry.remaining_amount_brl
                        ELSE 0
                    END), 0) AS monthly_debt_commitment_brl
                FROM finance_entry entry
                WHERE entry.owner_id = :ownerId
                  AND entry.deleted_at IS NULL
                  AND COALESCE(entry.competence_month, entry.due_date) >= :initialCompetenceMonth
                GROUP BY DATE_TRUNC('month', COALESCE(entry.competence_month, entry.due_date))
            ) budget_reference
        SQL, [
            'ownerId' => $ownerId,
            'initialCompetenceMonth' => $initialCompetenceMonth,
        ]);

        if (!is_array($budgetContext)) {
            return [
                'monthlyReceivablesBrl' => 0.0,
                'monthlyPayablesBrl' => 0.0,
                'monthlyDebtCommitmentBrl' => 0.0,
            ];
        }

        return [
            'monthlyReceivablesBrl' => max(0.0, $this->roundMoney((float) ($budgetContext['avg_receivables_brl'] ?? 0))),
            'monthlyPayablesBrl' => max(0.0, $this->roundMoney((float) ($budgetContext['avg_payables_brl'] ?? 0))),
            'monthlyDebtCommitmentBrl' => max(0.0, $this->roundMoney((float) ($budgetContext['avg_debt_commitment_brl'] ?? 0))),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function getPlanById(int $ownerId, int $debtPlanId): array
    {
        /** @var array<string, mixed>|false $debtPlan */
        $debtPlan = $this->connection->fetchAssociative(<<<'SQL'
            SELECT
                debt_plan.id,
                debt_plan.title,
                debt_plan.creditor_name AS "creditorName",
                debt_plan.notes,
                debt_plan.total_amount_brl AS "totalAmountBrl",
                debt_plan.negotiated_amount_brl AS "negotiatedAmountBrl",
                debt_plan.proposed_amount_brl AS "proposedAmountBrl",
                debt_plan.selected_reference_amount_brl AS "selectedReferenceAmountBrl",
                debt_plan.selected_reference_type AS "selectedReferenceType",
                debt_plan.down_payment_brl AS "downPaymentBrl",
                debt_plan.planned_total_amount_brl AS "plannedTotalAmountBrl",
                debt_plan.monthly_income_brl AS "monthlyIncomeBrl",
                debt_plan.max_commitment_percent AS "maxCommitmentPercent",
                debt_plan.max_recommended_payment_brl AS "maxRecommendedPaymentBrl",
                debt_plan.settlement_mode AS "settlementMode",
                debt_plan.selected_installments_count AS "selectedInstallmentsCount",
                debt_plan.selected_monthly_payment_brl AS "selectedMonthlyPaymentBrl",
                debt_plan.status,
                debt_plan.negotiation_note AS "negotiationNote",
                debt_plan.proposal_note AS "proposalNote",
                debt_plan.created_at AS "createdAt",
                debt_plan.updated_at AS "updatedAt",
                category.id AS "categoryId",
                category.name AS "categoryName",
                bank_account.id AS "bankAccountId",
                bank_account.name AS "bankAccountName",
                installment_plan.id AS "installmentPlanId",
                installment_plan.title AS "installmentPlanTitle",
                full_entry.id AS "fullPaymentEntryId",
                full_entry.title AS "fullPaymentEntryTitle"
            FROM finance_debt_plan debt_plan
            LEFT JOIN finance_category category ON category.id = debt_plan.category_id
            LEFT JOIN finance_bank_account bank_account ON bank_account.id = debt_plan.default_bank_account_id
            LEFT JOIN finance_installment_plan installment_plan ON installment_plan.id = debt_plan.linked_installment_plan_id
              AND installment_plan.deleted_at IS NULL
            LEFT JOIN finance_entry full_entry ON full_entry.id = debt_plan.full_payment_entry_id
              AND full_entry.deleted_at IS NULL
            WHERE debt_plan.owner_id = :ownerId
              AND debt_plan.id = :debtPlanId
              AND debt_plan.deleted_at IS NULL
            LIMIT 1
        SQL, [
            'ownerId' => $ownerId,
            'debtPlanId' => $debtPlanId,
        ]);

        if (!is_array($debtPlan)) {
            throw new \InvalidArgumentException('Plano de dívida não encontrado.');
        }

        return $debtPlan;
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
            throw new \InvalidArgumentException('Categoria não encontrada para o usuário atual.');
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
            throw new \InvalidArgumentException('Conta bancária não encontrada para o usuário atual.');
        }

        if (!(bool) $bankAccount['is_active']) {
            throw new \InvalidArgumentException('Contas bancárias inativas não podem ser usadas no plano de dívida.');
        }

        return $normalizedBankAccountId;
    }

    private function roundMoney(float $value): float
    {
        return round($value, 2);
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
