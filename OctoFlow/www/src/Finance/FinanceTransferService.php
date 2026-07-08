<?php

namespace App\Finance;

use App\Entity\User;
use Doctrine\DBAL\Connection;

final class FinanceTransferService
{
    private const DESTRUCTIVE_IMPORT_CONFIRMATION_PHRASE = 'IMPORTAR SNAPSHOT FINANCEIRO';

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function exportSnapshot(User $user): array
    {
        $ownerId = $this->requireOwnerId($user);

        return [
            'schema' => 'octoflow.finance.snapshot',
            'version' => 1,
            'exportedAt' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
            'sourceOwnerId' => $ownerId,
            'data' => [
                'categories' => $this->fetchOwnerTableRows('finance_category', $ownerId),
                'recurringTypes' => $this->fetchOwnerTableRows('finance_recurring_type', $ownerId),
                'bankAccounts' => $this->fetchOwnerTableRows('finance_bank_account', $ownerId),
                'recurringRules' => $this->fetchOwnerTableRows('finance_recurring_rule', $ownerId),
                'installmentPlans' => $this->fetchOwnerTableRows('finance_installment_plan', $ownerId),
                'installmentItems' => $this->fetchInstallmentItemsRows($ownerId),
                'investmentSimulations' => $this->fetchOwnerTableRows('finance_investment_simulation', $ownerId),
                'investmentSimulationPoints' => $this->fetchInvestmentSimulationPointsRows($ownerId),
                'investmentPlans' => $this->fetchOwnerTableRows('finance_investment_plan', $ownerId),
                'investmentPlanRuns' => $this->fetchInvestmentPlanRunsRows($ownerId),
                'externalConnections' => $this->fetchExternalConnectionsRows($ownerId),
                'externalAccounts' => $this->fetchExternalAccountsRows($ownerId),
                'externalTransactions' => $this->fetchExternalTransactionsRows($ownerId),
                'entries' => $this->fetchOwnerTableRows('finance_entry', $ownerId),
                'entrySettlements' => $this->fetchOwnerTableRows('finance_entry_settlement', $ownerId),
                'entryStatusHistory' => $this->fetchOwnerTableRows('finance_entry_status_history', $ownerId),
                'bankAccountLedger' => $this->fetchOwnerTableRows('finance_bank_account_ledger', $ownerId),
                'recurringRuleRuns' => $this->fetchRecurringRuleRunsRows($ownerId),
                'debtPlans' => $this->fetchOwnerTableRows('finance_debt_plan', $ownerId),
                'externalTransactionLinks' => $this->fetchExternalTransactionLinksRows($ownerId),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function importSnapshot(User $user, array $payload): array
    {
        $ownerId = $this->requireOwnerId($user);

        $snapshot = is_array($payload['snapshot'] ?? null) ? $payload['snapshot'] : $payload;
        $snapshotData = is_array($snapshot['data'] ?? null) ? $snapshot['data'] : [];
        if ($snapshotData === []) {
            throw new \InvalidArgumentException('Invalid snapshot payload. Field "data" is required.');
        }

        $replaceExisting = FinanceInput::normalizeBoolean($payload['replaceExisting'] ?? true, true);
        $this->assertDestructiveImportConfirmed($payload, $replaceExisting);

        $this->connection->beginTransaction();

        try {
            if ($replaceExisting) {
                $this->deleteOwnerFinanceData($ownerId);
            }

            $importSummary = $this->importSnapshotData($ownerId, $snapshotData);
            $this->connection->commit();

            return [
                'replaceExisting' => $replaceExisting,
                'snapshotVersion' => (int) ($snapshot['version'] ?? 1),
                'imported' => $importSummary,
            ];
        } catch (\Throwable $throwable) {
            $this->connection->rollBack();

            throw $throwable;
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function assertDestructiveImportConfirmed(array $payload, bool $replaceExisting): void
    {
        if (!$replaceExisting) {
            return;
        }

        $confirmationPhrase = is_string($payload['confirmationPhrase'] ?? null)
            ? trim($payload['confirmationPhrase'])
            : '';

        if ($confirmationPhrase !== self::DESTRUCTIVE_IMPORT_CONFIRMATION_PHRASE) {
            throw new \InvalidArgumentException(sprintf(
                'Importacao destrutiva exige confirmationPhrase exatamente igual a "%s".',
                self::DESTRUCTIVE_IMPORT_CONFIRMATION_PHRASE,
            ));
        }
    }

    /**
     * @param array<string, mixed> $snapshotData
     *
     * @return array<string, int>
     */
    private function importSnapshotData(int $ownerId, array $snapshotData): array
    {
        $categoryMap = [];
        $recurringTypeMap = [];
        $bankAccountMap = [];
        $recurringRuleMap = [];
        $installmentPlanMap = [];
        $installmentItemMap = [];
        $investmentSimulationMap = [];
        $investmentPlanMap = [];
        $investmentPlanRunMap = [];
        $externalConnectionMap = [];
        $externalAccountMap = [];
        $externalTransactionMap = [];
        $entryMap = [];
        $entrySettlementMap = [];

        $entriesPendingInstallmentItemMap = [];
        $entriesPendingInvestmentPlanRunMap = [];

        $importedCounters = $this->buildImportCounters();

        $categoriesRows = $this->readSnapshotRows($snapshotData, 'categories');
        foreach ($categoriesRows as $categoryRow) {
            $newCategoryId = $this->insertCategoryRow($ownerId, $categoryRow);
            $this->registerMappedId($categoryMap, $categoryRow, $newCategoryId);
            $importedCounters['categories'] += 1;
        }

        $recurringTypesRows = $this->readSnapshotRows($snapshotData, 'recurringTypes');
        foreach ($recurringTypesRows as $recurringTypeRow) {
            $newRecurringTypeId = $this->insertRecurringTypeRow($ownerId, $recurringTypeRow);
            $this->registerMappedId($recurringTypeMap, $recurringTypeRow, $newRecurringTypeId);
            $importedCounters['recurringTypes'] += 1;
        }

        $bankAccountsRows = $this->readSnapshotRows($snapshotData, 'bankAccounts');
        foreach ($bankAccountsRows as $bankAccountRow) {
            $newBankAccountId = $this->insertBankAccountRow($ownerId, $bankAccountRow);
            $this->registerMappedId($bankAccountMap, $bankAccountRow, $newBankAccountId);
            $importedCounters['bankAccounts'] += 1;
        }

        $recurringRulesRows = $this->readSnapshotRows($snapshotData, 'recurringRules');
        foreach ($recurringRulesRows as $recurringRuleRow) {
            $newRecurringRuleId = $this->insertRecurringRuleRow($ownerId, $recurringRuleRow, $recurringTypeMap, $categoryMap, $bankAccountMap);
            $this->registerMappedId($recurringRuleMap, $recurringRuleRow, $newRecurringRuleId);
            $importedCounters['recurringRules'] += 1;
        }

        $installmentPlansRows = $this->readSnapshotRows($snapshotData, 'installmentPlans');
        foreach ($installmentPlansRows as $installmentPlanRow) {
            $newInstallmentPlanId = $this->insertInstallmentPlanRow($ownerId, $installmentPlanRow, $categoryMap, $bankAccountMap);
            $this->registerMappedId($installmentPlanMap, $installmentPlanRow, $newInstallmentPlanId);
            $importedCounters['installmentPlans'] += 1;
        }

        $investmentSimulationsRows = $this->readSnapshotRows($snapshotData, 'investmentSimulations');
        foreach ($investmentSimulationsRows as $investmentSimulationRow) {
            $newInvestmentSimulationId = $this->insertInvestmentSimulationRow($ownerId, $investmentSimulationRow);
            $this->registerMappedId($investmentSimulationMap, $investmentSimulationRow, $newInvestmentSimulationId);
            $importedCounters['investmentSimulations'] += 1;
        }

        $investmentSimulationPointsRows = $this->readSnapshotRows($snapshotData, 'investmentSimulationPoints');
        foreach ($investmentSimulationPointsRows as $investmentSimulationPointRow) {
            $this->insertInvestmentSimulationPointRow($investmentSimulationPointRow, $investmentSimulationMap);
            $importedCounters['investmentSimulationPoints'] += 1;
        }

        $investmentPlansRows = $this->readSnapshotRows($snapshotData, 'investmentPlans');
        foreach ($investmentPlansRows as $investmentPlanRow) {
            $newInvestmentPlanId = $this->insertInvestmentPlanRow($ownerId, $investmentPlanRow, $investmentSimulationMap, $bankAccountMap, $categoryMap);
            $this->registerMappedId($investmentPlanMap, $investmentPlanRow, $newInvestmentPlanId);
            $importedCounters['investmentPlans'] += 1;
        }

        $externalConnectionsRows = $this->readSnapshotRows($snapshotData, 'externalConnections');
        foreach ($externalConnectionsRows as $externalConnectionRow) {
            $newExternalConnectionId = $this->insertExternalConnectionRow($ownerId, $externalConnectionRow);
            $this->registerMappedId($externalConnectionMap, $externalConnectionRow, $newExternalConnectionId);
            $importedCounters['externalConnections'] += 1;
        }

        $externalAccountsRows = $this->readSnapshotRows($snapshotData, 'externalAccounts');
        foreach ($externalAccountsRows as $externalAccountRow) {
            $newExternalAccountId = $this->insertExternalAccountRow($externalAccountRow, $externalConnectionMap);
            $this->registerMappedId($externalAccountMap, $externalAccountRow, $newExternalAccountId);
            $importedCounters['externalAccounts'] += 1;
        }

        $externalTransactionsRows = $this->readSnapshotRows($snapshotData, 'externalTransactions');
        foreach ($externalTransactionsRows as $externalTransactionRow) {
            $newExternalTransactionId = $this->insertExternalTransactionRow($externalTransactionRow, $externalAccountMap);
            $this->registerMappedId($externalTransactionMap, $externalTransactionRow, $newExternalTransactionId);
            $importedCounters['externalTransactions'] += 1;
        }

        $entriesRows = $this->readSnapshotRows($snapshotData, 'entries');
        foreach ($entriesRows as $entryRow) {
            $newEntryId = $this->insertEntryRow(
                $ownerId,
                $entryRow,
                $categoryMap,
                $bankAccountMap,
                $recurringRuleMap,
                $externalTransactionMap,
                $entriesPendingInstallmentItemMap,
                $entriesPendingInvestmentPlanRunMap,
            );
            $this->registerMappedId($entryMap, $entryRow, $newEntryId);
            $importedCounters['entries'] += 1;
        }

        $installmentItemsRows = $this->readSnapshotRows($snapshotData, 'installmentItems');
        foreach ($installmentItemsRows as $installmentItemRow) {
            $newInstallmentItemId = $this->insertInstallmentItemRow($installmentItemRow, $installmentPlanMap, $entryMap);
            $this->registerMappedId($installmentItemMap, $installmentItemRow, $newInstallmentItemId);
            $importedCounters['installmentItems'] += 1;
        }

        $investmentPlanRunsRows = $this->readSnapshotRows($snapshotData, 'investmentPlanRuns');
        foreach ($investmentPlanRunsRows as $investmentPlanRunRow) {
            $newInvestmentPlanRunId = $this->insertInvestmentPlanRunRow($investmentPlanRunRow, $investmentPlanMap, $entryMap);
            $this->registerMappedId($investmentPlanRunMap, $investmentPlanRunRow, $newInvestmentPlanRunId);
            $importedCounters['investmentPlanRuns'] += 1;
        }

        $this->applyEntryCycleReferences($ownerId, $entriesPendingInstallmentItemMap, $entriesPendingInvestmentPlanRunMap, $entryMap, $installmentItemMap, $investmentPlanRunMap);

        $entrySettlementsRows = $this->readSnapshotRows($snapshotData, 'entrySettlements');
        foreach ($entrySettlementsRows as $entrySettlementRow) {
            $newEntrySettlementId = $this->insertEntrySettlementRow($ownerId, $entrySettlementRow, $entryMap, $bankAccountMap);
            $this->registerMappedId($entrySettlementMap, $entrySettlementRow, $newEntrySettlementId);
            $importedCounters['entrySettlements'] += 1;
        }

        $entryStatusHistoryRows = $this->readSnapshotRows($snapshotData, 'entryStatusHistory');
        foreach ($entryStatusHistoryRows as $entryStatusHistoryRow) {
            $this->insertEntryStatusHistoryRow($ownerId, $entryStatusHistoryRow, $entryMap);
            $importedCounters['entryStatusHistory'] += 1;
        }

        $bankAccountLedgerRows = $this->readSnapshotRows($snapshotData, 'bankAccountLedger');
        foreach ($bankAccountLedgerRows as $bankAccountLedgerRow) {
            $this->insertBankAccountLedgerRow($ownerId, $bankAccountLedgerRow, $bankAccountMap, $entryMap, $entrySettlementMap);
            $importedCounters['bankAccountLedger'] += 1;
        }

        $recurringRuleRunsRows = $this->readSnapshotRows($snapshotData, 'recurringRuleRuns');
        foreach ($recurringRuleRunsRows as $recurringRuleRunRow) {
            $this->insertRecurringRuleRunRow($recurringRuleRunRow, $recurringRuleMap, $entryMap);
            $importedCounters['recurringRuleRuns'] += 1;
        }

        $debtPlansRows = $this->readSnapshotRows($snapshotData, 'debtPlans');
        foreach ($debtPlansRows as $debtPlanRow) {
            $this->insertDebtPlanRow($ownerId, $debtPlanRow, $categoryMap, $bankAccountMap, $installmentPlanMap, $entryMap);
            $importedCounters['debtPlans'] += 1;
        }

        $externalTransactionLinksRows = $this->readSnapshotRows($snapshotData, 'externalTransactionLinks');
        foreach ($externalTransactionLinksRows as $externalTransactionLinkRow) {
            $this->insertExternalTransactionLinkRow($externalTransactionLinkRow, $externalTransactionMap, $entryMap);
            $importedCounters['externalTransactionLinks'] += 1;
        }

        return $importedCounters;
    }

    /**
     * @param list<array<string, mixed>> $entriesPendingInstallmentItemMap
     * @param list<array<string, mixed>> $entriesPendingInvestmentPlanRunMap
     * @param array<int, int> $entryMap
     * @param array<int, int> $installmentItemMap
     * @param array<int, int> $investmentPlanRunMap
     */
    private function applyEntryCycleReferences(
        int $ownerId,
        array $entriesPendingInstallmentItemMap,
        array $entriesPendingInvestmentPlanRunMap,
        array $entryMap,
        array $installmentItemMap,
        array $investmentPlanRunMap,
    ): void {
        foreach ($entriesPendingInstallmentItemMap as $entryPendingInstallmentItem) {
            $oldEntryId = (int) ($entryPendingInstallmentItem['entryId'] ?? 0);
            $oldInstallmentItemId = (int) ($entryPendingInstallmentItem['installmentItemId'] ?? 0);

            if ($oldEntryId <= 0 || $oldInstallmentItemId <= 0) {
                continue;
            }

            $newEntryId = $entryMap[$oldEntryId] ?? null;
            $newInstallmentItemId = $installmentItemMap[$oldInstallmentItemId] ?? null;

            if ($newEntryId === null || $newInstallmentItemId === null) {
                continue;
            }

            $this->connection->update('finance_entry', [
                'installment_item_id' => $newInstallmentItemId,
            ], [
                'owner_id' => $ownerId,
                'id' => $newEntryId,
            ]);
        }

        foreach ($entriesPendingInvestmentPlanRunMap as $entryPendingInvestmentPlanRun) {
            $oldEntryId = (int) ($entryPendingInvestmentPlanRun['entryId'] ?? 0);
            $oldInvestmentPlanRunId = (int) ($entryPendingInvestmentPlanRun['investmentPlanRunId'] ?? 0);

            if ($oldEntryId <= 0 || $oldInvestmentPlanRunId <= 0) {
                continue;
            }

            $newEntryId = $entryMap[$oldEntryId] ?? null;
            $newInvestmentPlanRunId = $investmentPlanRunMap[$oldInvestmentPlanRunId] ?? null;

            if ($newEntryId === null || $newInvestmentPlanRunId === null) {
                continue;
            }

            $this->connection->update('finance_entry', [
                'investment_plan_run_id' => $newInvestmentPlanRunId,
            ], [
                'owner_id' => $ownerId,
                'id' => $newEntryId,
            ]);
        }
    }

    /**
     * @param array<int, int> $categoryMap
     * @param array<int, int> $bankAccountMap
     */
    private function insertDebtPlanRow(
        int $ownerId,
        array $debtPlanRow,
        array $categoryMap,
        array $bankAccountMap,
        array $installmentPlanMap,
        array $entryMap,
    ): void {
        $nowLabel = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $this->connection->insert('finance_debt_plan', [
            'owner_id' => $ownerId,
            'category_id' => $this->mapOptionalForeignId($categoryMap, $debtPlanRow['category_id'] ?? null),
            'default_bank_account_id' => $this->mapOptionalForeignId($bankAccountMap, $debtPlanRow['default_bank_account_id'] ?? null),
            'linked_installment_plan_id' => $this->mapOptionalForeignId($installmentPlanMap, $debtPlanRow['linked_installment_plan_id'] ?? null),
            'full_payment_entry_id' => $this->mapOptionalForeignId($entryMap, $debtPlanRow['full_payment_entry_id'] ?? null),
            'title' => $this->normalizeStringOrDefault($debtPlanRow['title'] ?? null, 'Plano de dívida importado'),
            'creditor_name' => $this->normalizeNullableString($debtPlanRow['creditor_name'] ?? null),
            'notes' => $this->normalizeNullableString($debtPlanRow['notes'] ?? null),
            'total_amount_brl' => $this->normalizeNumericOrDefault($debtPlanRow['total_amount_brl'] ?? null, 0.0),
            'negotiated_amount_brl' => $this->normalizeNullableNumeric($debtPlanRow['negotiated_amount_brl'] ?? null),
            'proposed_amount_brl' => $this->normalizeNullableNumeric($debtPlanRow['proposed_amount_brl'] ?? null),
            'selected_reference_amount_brl' => $this->normalizeNumericOrDefault($debtPlanRow['selected_reference_amount_brl'] ?? null, 0.0),
            'selected_reference_type' => $this->normalizeStringOrDefault($debtPlanRow['selected_reference_type'] ?? null, 'FULL'),
            'down_payment_brl' => $this->normalizeNumericOrDefault($debtPlanRow['down_payment_brl'] ?? null, 0.0),
            'planned_total_amount_brl' => $this->normalizeNumericOrDefault($debtPlanRow['planned_total_amount_brl'] ?? null, 0.0),
            'monthly_income_brl' => $this->normalizeNumericOrDefault($debtPlanRow['monthly_income_brl'] ?? null, 0.0),
            'max_commitment_percent' => $this->normalizeNumericOrDefault($debtPlanRow['max_commitment_percent'] ?? null, 30.0),
            'max_recommended_payment_brl' => $this->normalizeNumericOrDefault($debtPlanRow['max_recommended_payment_brl'] ?? null, 0.0),
            'settlement_mode' => $this->normalizeStringOrDefault($debtPlanRow['settlement_mode'] ?? null, 'INSTALLMENT'),
            'selected_installments_count' => $this->normalizeIntegerOrDefault($debtPlanRow['selected_installments_count'] ?? null, 1),
            'selected_monthly_payment_brl' => $this->normalizeNumericOrDefault($debtPlanRow['selected_monthly_payment_brl'] ?? null, 0.0),
            'status' => $this->normalizeStringOrDefault($debtPlanRow['status'] ?? null, 'PLANNED'),
            'negotiation_note' => $this->normalizeNullableString($debtPlanRow['negotiation_note'] ?? null),
            'proposal_note' => $this->normalizeNullableString($debtPlanRow['proposal_note'] ?? null),
            'created_at' => $this->normalizeDateTimeOrDefault($debtPlanRow['created_at'] ?? null, $nowLabel),
            'updated_at' => $this->normalizeDateTimeOrDefault($debtPlanRow['updated_at'] ?? null, $nowLabel),
            'deleted_at' => $this->normalizeNullableDateTime($debtPlanRow['deleted_at'] ?? null),
        ]);
    }

    /**
     * @param array<int, int> $externalTransactionMap
     * @param array<int, int> $entryMap
     */
    private function insertExternalTransactionLinkRow(array $externalTransactionLinkRow, array $externalTransactionMap, array $entryMap): void
    {
        $oldExternalTransactionId = (int) ($externalTransactionLinkRow['external_transaction_id'] ?? 0);
        $oldEntryId = (int) ($externalTransactionLinkRow['entry_id'] ?? 0);

        $newExternalTransactionId = $externalTransactionMap[$oldExternalTransactionId] ?? null;
        $newEntryId = $entryMap[$oldEntryId] ?? null;

        if ($newExternalTransactionId === null || $newEntryId === null) {
            throw new \InvalidArgumentException('Invalid external transaction link row in snapshot.');
        }

        $this->connection->insert('finance_external_transaction_link', [
            'external_transaction_id' => $newExternalTransactionId,
            'entry_id' => $newEntryId,
            'linked_at' => $this->normalizeDateTimeOrDefault($externalTransactionLinkRow['linked_at'] ?? null, (new \DateTimeImmutable())->format('Y-m-d H:i:s')),
        ]);
    }

    /**
     * @param array<int, int> $recurringRuleMap
     * @param array<int, int> $entryMap
     */
    private function insertRecurringRuleRunRow(array $recurringRuleRunRow, array $recurringRuleMap, array $entryMap): void
    {
        $newRecurringRuleId = $this->mapRequiredForeignId($recurringRuleMap, $recurringRuleRunRow['recurring_rule_id'] ?? null, 'finance_recurring_rule_run.recurring_rule_id');
        $newGeneratedEntryId = $this->mapRequiredForeignId($entryMap, $recurringRuleRunRow['generated_entry_id'] ?? null, 'finance_recurring_rule_run.generated_entry_id');

        $this->connection->insert('finance_recurring_rule_run', [
            'recurring_rule_id' => $newRecurringRuleId,
            'generated_entry_id' => $newGeneratedEntryId,
            'competence_month' => $this->normalizeDateOrDefault($recurringRuleRunRow['competence_month'] ?? null, (new \DateTimeImmutable('today'))->format('Y-m-01')),
            'run_source' => $this->normalizeStringOrDefault($recurringRuleRunRow['run_source'] ?? null, 'import'),
            'generated_at' => $this->normalizeDateTimeOrDefault($recurringRuleRunRow['generated_at'] ?? null, (new \DateTimeImmutable())->format('Y-m-d H:i:s')),
        ]);
    }

    /**
     * @param array<int, int> $bankAccountMap
     * @param array<int, int> $entryMap
     * @param array<int, int> $entrySettlementMap
     */
    private function insertBankAccountLedgerRow(
        int $ownerId,
        array $bankAccountLedgerRow,
        array $bankAccountMap,
        array $entryMap,
        array $entrySettlementMap,
    ): void {
        $newBankAccountId = $this->mapRequiredForeignId($bankAccountMap, $bankAccountLedgerRow['bank_account_id'] ?? null, 'finance_bank_account_ledger.bank_account_id');

        $this->connection->insert('finance_bank_account_ledger', [
            'owner_id' => $ownerId,
            'bank_account_id' => $newBankAccountId,
            'entry_id' => $this->mapOptionalForeignId($entryMap, $bankAccountLedgerRow['entry_id'] ?? null),
            'settlement_id' => $this->mapOptionalForeignId($entrySettlementMap, $bankAccountLedgerRow['settlement_id'] ?? null),
            'movement_type' => $this->normalizeStringOrDefault($bankAccountLedgerRow['movement_type'] ?? null, 'ADJUSTMENT'),
            'amount_brl' => $this->normalizeNumericOrDefault($bankAccountLedgerRow['amount_brl'] ?? null, 0.0),
            'balance_after_brl' => $this->normalizeNumericOrDefault($bankAccountLedgerRow['balance_after_brl'] ?? null, 0.0),
            'happened_at' => $this->normalizeDateTimeOrDefault($bankAccountLedgerRow['happened_at'] ?? null, (new \DateTimeImmutable())->format('Y-m-d H:i:s')),
            'source_type' => $this->normalizeStringOrDefault($bankAccountLedgerRow['source_type'] ?? null, 'IMPORT'),
        ]);
    }

    /**
     * @param array<int, int> $entryMap
     */
    private function insertEntryStatusHistoryRow(int $ownerId, array $entryStatusHistoryRow, array $entryMap): void
    {
        $newEntryId = $this->mapRequiredForeignId($entryMap, $entryStatusHistoryRow['entry_id'] ?? null, 'finance_entry_status_history.entry_id');

        $this->connection->insert('finance_entry_status_history', [
            'owner_id' => $ownerId,
            'entry_id' => $newEntryId,
            'from_status' => $this->normalizeNullableString($entryStatusHistoryRow['from_status'] ?? null),
            'to_status' => $this->normalizeStringOrDefault($entryStatusHistoryRow['to_status'] ?? null, 'PENDING'),
            'reason_code' => $this->normalizeNullableString($entryStatusHistoryRow['reason_code'] ?? null),
            'reason_text' => $this->normalizeNullableString($entryStatusHistoryRow['reason_text'] ?? null),
            'changed_at' => $this->normalizeDateTimeOrDefault($entryStatusHistoryRow['changed_at'] ?? null, (new \DateTimeImmutable())->format('Y-m-d H:i:s')),
        ]);
    }

    /**
     * @param array<int, int> $entryMap
     * @param array<int, int> $bankAccountMap
     */
    private function insertEntrySettlementRow(int $ownerId, array $entrySettlementRow, array $entryMap, array $bankAccountMap): int
    {
        $newEntryId = $this->mapRequiredForeignId($entryMap, $entrySettlementRow['entry_id'] ?? null, 'finance_entry_settlement.entry_id');

        $this->connection->insert('finance_entry_settlement', [
            'owner_id' => $ownerId,
            'entry_id' => $newEntryId,
            'bank_account_id' => $this->mapOptionalForeignId($bankAccountMap, $entrySettlementRow['bank_account_id'] ?? null),
            'settlement_type' => $this->normalizeStringOrDefault($entrySettlementRow['settlement_type'] ?? null, 'MANUAL'),
            'amount_brl' => $this->normalizeNumericOrDefault($entrySettlementRow['amount_brl'] ?? null, 0.0),
            'settled_at' => $this->normalizeDateTimeOrDefault($entrySettlementRow['settled_at'] ?? null, (new \DateTimeImmutable())->format('Y-m-d H:i:s')),
            'note' => $this->normalizeNullableString($entrySettlementRow['note'] ?? null),
            'created_at' => $this->normalizeDateTimeOrDefault($entrySettlementRow['created_at'] ?? null, (new \DateTimeImmutable())->format('Y-m-d H:i:s')),
        ]);

        return (int) $this->connection->lastInsertId();
    }

    /**
     * @param array<int, int> $categoryMap
     * @param array<int, int> $bankAccountMap
     * @param array<int, int> $recurringRuleMap
     * @param array<int, int> $externalTransactionMap
     * @param list<array<string, int>> $entriesPendingInstallmentItemMap
     * @param list<array<string, int>> $entriesPendingInvestmentPlanRunMap
     */
    private function insertEntryRow(
        int $ownerId,
        array $entryRow,
        array $categoryMap,
        array $bankAccountMap,
        array $recurringRuleMap,
        array $externalTransactionMap,
        array &$entriesPendingInstallmentItemMap,
        array &$entriesPendingInvestmentPlanRunMap,
    ): int {
        $oldInstallmentItemId = (int) ($entryRow['installment_item_id'] ?? 0);
        $oldInvestmentPlanRunId = (int) ($entryRow['investment_plan_run_id'] ?? 0);

        $nowLabel = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $this->connection->insert('finance_entry', [
            'owner_id' => $ownerId,
            'category_id' => $this->mapOptionalForeignId($categoryMap, $entryRow['category_id'] ?? null),
            'bank_account_id' => $this->mapOptionalForeignId($bankAccountMap, $entryRow['bank_account_id'] ?? null),
            'recurring_rule_id' => $this->mapOptionalForeignId($recurringRuleMap, $entryRow['recurring_rule_id'] ?? null),
            'installment_item_id' => null,
            'investment_plan_run_id' => null,
            'external_transaction_id' => $this->mapOptionalForeignId($externalTransactionMap, $entryRow['external_transaction_id'] ?? null),
            'direction' => $this->normalizeStringOrDefault($entryRow['direction'] ?? null, FinanceConstants::DIRECTION_PAYABLE),
            'entry_type' => $this->normalizeStringOrDefault($entryRow['entry_type'] ?? null, FinanceConstants::ENTRY_TYPE_ONE_OFF),
            'status' => $this->normalizeStringOrDefault($entryRow['status'] ?? null, 'PENDING'),
            'title' => $this->normalizeStringOrDefault($entryRow['title'] ?? null, 'Lançamento importado'),
            'description' => $this->normalizeNullableString($entryRow['description'] ?? null),
            'due_date' => $this->normalizeNullableDate($entryRow['due_date'] ?? null),
            'competence_month' => $this->normalizeNullableDate($entryRow['competence_month'] ?? null),
            'expected_amount_brl' => $this->normalizeNumericOrDefault($entryRow['expected_amount_brl'] ?? null, 0.0),
            'settled_amount_brl' => $this->normalizeNumericOrDefault($entryRow['settled_amount_brl'] ?? null, 0.0),
            'remaining_amount_brl' => $this->normalizeNumericOrDefault($entryRow['remaining_amount_brl'] ?? null, 0.0),
            'input_currency_code' => $this->normalizeStringOrDefault($entryRow['input_currency_code'] ?? null, 'BRL'),
            'input_amount' => $this->normalizeNullableNumeric($entryRow['input_amount'] ?? null),
            'fx_rate_to_brl' => $this->normalizeNullableNumeric($entryRow['fx_rate_to_brl'] ?? null),
            'fx_rate_date' => $this->normalizeNullableDate($entryRow['fx_rate_date'] ?? null),
            'source_origin' => $this->normalizeStringOrDefault($entryRow['source_origin'] ?? null, FinanceConstants::SOURCE_ORIGIN_IMPORTED),
            'source_system' => $this->normalizeNullableString($entryRow['source_system'] ?? null),
            'fully_settled_at' => $this->normalizeNullableDateTime($entryRow['fully_settled_at'] ?? null),
            'created_at' => $this->normalizeDateTimeOrDefault($entryRow['created_at'] ?? null, $nowLabel),
            'updated_at' => $this->normalizeDateTimeOrDefault($entryRow['updated_at'] ?? null, $nowLabel),
            'deleted_at' => $this->normalizeNullableDateTime($entryRow['deleted_at'] ?? null),
        ]);

        $newEntryId = (int) $this->connection->lastInsertId();

        if ($oldInstallmentItemId > 0) {
            $entriesPendingInstallmentItemMap[] = [
                'entryId' => (int) ($entryRow['id'] ?? 0),
                'installmentItemId' => $oldInstallmentItemId,
            ];
        }

        if ($oldInvestmentPlanRunId > 0) {
            $entriesPendingInvestmentPlanRunMap[] = [
                'entryId' => (int) ($entryRow['id'] ?? 0),
                'investmentPlanRunId' => $oldInvestmentPlanRunId,
            ];
        }

        return $newEntryId;
    }

    /**
     * @param array<int, int> $installmentPlanMap
     * @param array<int, int> $entryMap
     */
    private function insertInstallmentItemRow(array $installmentItemRow, array $installmentPlanMap, array $entryMap): int
    {
        $newPlanId = $this->mapRequiredForeignId($installmentPlanMap, $installmentItemRow['plan_id'] ?? null, 'finance_installment_item.plan_id');

        $this->connection->insert('finance_installment_item', [
            'plan_id' => $newPlanId,
            'entry_id' => $this->mapOptionalForeignId($entryMap, $installmentItemRow['entry_id'] ?? null),
            'installment_number' => $this->normalizeIntegerOrDefault($installmentItemRow['installment_number'] ?? null, 1),
            'due_date' => $this->normalizeDateOrDefault($installmentItemRow['due_date'] ?? null, (new \DateTimeImmutable('today'))->format('Y-m-d')),
            'expected_amount_brl' => $this->normalizeNumericOrDefault($installmentItemRow['expected_amount_brl'] ?? null, 0.0),
            'created_at' => $this->normalizeDateTimeOrDefault($installmentItemRow['created_at'] ?? null, (new \DateTimeImmutable())->format('Y-m-d H:i:s')),
        ]);

        return (int) $this->connection->lastInsertId();
    }

    /**
     * @param array<int, int> $investmentPlanMap
     * @param array<int, int> $entryMap
     */
    private function insertInvestmentPlanRunRow(array $investmentPlanRunRow, array $investmentPlanMap, array $entryMap): int
    {
        $newInvestmentPlanId = $this->mapRequiredForeignId($investmentPlanMap, $investmentPlanRunRow['investment_plan_id'] ?? null, 'finance_investment_plan_run.investment_plan_id');

        $this->connection->insert('finance_investment_plan_run', [
            'investment_plan_id' => $newInvestmentPlanId,
            'contribution_entry_id' => $this->mapOptionalForeignId($entryMap, $investmentPlanRunRow['contribution_entry_id'] ?? null),
            'yield_entry_id' => $this->mapOptionalForeignId($entryMap, $investmentPlanRunRow['yield_entry_id'] ?? null),
            'competence_month' => $this->normalizeDateOrDefault($investmentPlanRunRow['competence_month'] ?? null, (new \DateTimeImmutable('today'))->format('Y-m-01')),
            'run_source' => $this->normalizeStringOrDefault($investmentPlanRunRow['run_source'] ?? null, 'import'),
            'generated_at' => $this->normalizeDateTimeOrDefault($investmentPlanRunRow['generated_at'] ?? null, (new \DateTimeImmutable())->format('Y-m-d H:i:s')),
        ]);

        return (int) $this->connection->lastInsertId();
    }

    /**
     * @param array<int, int> $externalAccountMap
     */
    private function insertExternalTransactionRow(array $externalTransactionRow, array $externalAccountMap): int
    {
        $newExternalAccountId = $this->mapRequiredForeignId($externalAccountMap, $externalTransactionRow['external_account_id'] ?? null, 'finance_external_transaction.external_account_id');

        $this->connection->insert('finance_external_transaction', [
            'external_account_id' => $newExternalAccountId,
            'external_transaction_id' => $this->normalizeStringOrDefault($externalTransactionRow['external_transaction_id'] ?? null, sprintf('IMPORTED_%s', uniqid())),
            'posted_at' => $this->normalizeDateTimeOrDefault($externalTransactionRow['posted_at'] ?? null, (new \DateTimeImmutable())->format('Y-m-d H:i:s')),
            'amount' => $this->normalizeNumericOrDefault($externalTransactionRow['amount'] ?? null, 0.0),
            'currency_code' => $this->normalizeStringOrDefault($externalTransactionRow['currency_code'] ?? null, 'BRL'),
            'direction' => $this->normalizeStringOrDefault($externalTransactionRow['direction'] ?? null, FinanceConstants::DIRECTION_PAYABLE),
            'description' => $this->normalizeNullableString($externalTransactionRow['description'] ?? null),
            'raw_payload' => $this->normalizeJsonString($externalTransactionRow['raw_payload'] ?? null),
            'normalized_category' => $this->normalizeNullableString($externalTransactionRow['normalized_category'] ?? null),
        ]);

        return (int) $this->connection->lastInsertId();
    }

    /**
     * @param array<int, int> $externalConnectionMap
     */
    private function insertExternalAccountRow(array $externalAccountRow, array $externalConnectionMap): int
    {
        $newConnectionId = $this->mapRequiredForeignId($externalConnectionMap, $externalAccountRow['connection_id'] ?? null, 'finance_external_account.connection_id');

        $this->connection->insert('finance_external_account', [
            'connection_id' => $newConnectionId,
            'external_account_id' => $this->normalizeStringOrDefault($externalAccountRow['external_account_id'] ?? null, sprintf('IMPORTED_ACC_%s', uniqid())),
            'display_name' => $this->normalizeStringOrDefault($externalAccountRow['display_name'] ?? null, 'Conta externa importada'),
            'bank_name' => $this->normalizeNullableString($externalAccountRow['bank_name'] ?? null),
            'account_type' => $this->normalizeNullableString($externalAccountRow['account_type'] ?? null),
            'currency_code' => $this->normalizeStringOrDefault($externalAccountRow['currency_code'] ?? null, 'BRL'),
            'is_active' => FinanceInput::toDatabaseBoolean(FinanceInput::normalizeBoolean($externalAccountRow['is_active'] ?? true, true)),
        ]);

        return (int) $this->connection->lastInsertId();
    }

    private function insertExternalConnectionRow(int $ownerId, array $externalConnectionRow): int
    {
        $providerId = $this->resolveProviderIdBySnapshotRow($externalConnectionRow);

        $this->connection->insert('finance_external_connection', [
            'owner_id' => $ownerId,
            'provider_id' => $providerId,
            'external_consent_id' => $this->normalizeNullableString($externalConnectionRow['external_consent_id'] ?? null),
            'status' => $this->normalizeStringOrDefault($externalConnectionRow['status'] ?? null, 'ACTIVE'),
            'consent_expires_at' => $this->normalizeNullableDateTime($externalConnectionRow['consent_expires_at'] ?? null),
            'last_sync_at' => $this->normalizeNullableDateTime($externalConnectionRow['last_sync_at'] ?? null),
            'created_at' => $this->normalizeDateTimeOrDefault($externalConnectionRow['created_at'] ?? null, (new \DateTimeImmutable())->format('Y-m-d H:i:s')),
        ]);

        return (int) $this->connection->lastInsertId();
    }

    /**
     * @param array<int, int> $investmentSimulationMap
     * @param array<int, int> $bankAccountMap
     * @param array<int, int> $categoryMap
     */
    private function insertInvestmentPlanRow(int $ownerId, array $investmentPlanRow, array $investmentSimulationMap, array $bankAccountMap, array $categoryMap): int
    {
        $nowLabel = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $this->connection->insert('finance_investment_plan', [
            'owner_id' => $ownerId,
            'source_simulation_id' => $this->mapOptionalForeignId($investmentSimulationMap, $investmentPlanRow['source_simulation_id'] ?? null),
            'default_bank_account_id' => $this->mapOptionalForeignId($bankAccountMap, $investmentPlanRow['default_bank_account_id'] ?? null),
            'category_id' => $this->mapOptionalForeignId($categoryMap, $investmentPlanRow['category_id'] ?? null),
            'label' => $this->normalizeStringOrDefault($investmentPlanRow['label'] ?? null, 'Plano de investimento importado'),
            'investment_type' => $this->normalizeStringOrDefault($investmentPlanRow['investment_type'] ?? null, 'CUSTOM'),
            'start_date' => $this->normalizeDateOrDefault($investmentPlanRow['start_date'] ?? null, (new \DateTimeImmutable('today'))->format('Y-m-d')),
            'contribution_day' => $this->normalizeIntegerOrDefault($investmentPlanRow['contribution_day'] ?? null, 1),
            'monthly_contribution_brl' => $this->normalizeNumericOrDefault($investmentPlanRow['monthly_contribution_brl'] ?? null, 0.0),
            'effective_monthly_rate' => $this->normalizeNumericOrDefault($investmentPlanRow['effective_monthly_rate'] ?? null, 0.0),
            'generate_yield_entries' => FinanceInput::toDatabaseBoolean(FinanceInput::normalizeBoolean($investmentPlanRow['generate_yield_entries'] ?? false, false)),
            'yield_mode' => $this->normalizeStringOrDefault($investmentPlanRow['yield_mode'] ?? null, 'NONE'),
            'status' => $this->normalizeStringOrDefault($investmentPlanRow['status'] ?? null, 'ACTIVE'),
            'created_at' => $this->normalizeDateTimeOrDefault($investmentPlanRow['created_at'] ?? null, $nowLabel),
            'updated_at' => $this->normalizeDateTimeOrDefault($investmentPlanRow['updated_at'] ?? null, $nowLabel),
        ]);

        return (int) $this->connection->lastInsertId();
    }

    /**
     * @param array<int, int> $investmentSimulationMap
     */
    private function insertInvestmentSimulationPointRow(array $investmentSimulationPointRow, array $investmentSimulationMap): void
    {
        $newSimulationId = $this->mapRequiredForeignId($investmentSimulationMap, $investmentSimulationPointRow['simulation_id'] ?? null, 'finance_investment_simulation_point.simulation_id');

        $this->connection->insert('finance_investment_simulation_point', [
            'simulation_id' => $newSimulationId,
            'month_index' => $this->normalizeIntegerOrDefault($investmentSimulationPointRow['month_index'] ?? null, 1),
            'invested_amount_brl' => $this->normalizeNumericOrDefault($investmentSimulationPointRow['invested_amount_brl'] ?? null, 0.0),
            'yield_amount_brl' => $this->normalizeNumericOrDefault($investmentSimulationPointRow['yield_amount_brl'] ?? null, 0.0),
            'total_amount_brl' => $this->normalizeNumericOrDefault($investmentSimulationPointRow['total_amount_brl'] ?? null, 0.0),
        ]);
    }

    private function insertInvestmentSimulationRow(int $ownerId, array $investmentSimulationRow): int
    {
        $this->connection->insert('finance_investment_simulation', [
            'owner_id' => $ownerId,
            'investment_type' => $this->normalizeStringOrDefault($investmentSimulationRow['investment_type'] ?? null, 'CUSTOM'),
            'label' => $this->normalizeStringOrDefault($investmentSimulationRow['label'] ?? null, 'Simulação importada'),
            'initial_amount_brl' => $this->normalizeNumericOrDefault($investmentSimulationRow['initial_amount_brl'] ?? null, 0.0),
            'monthly_contribution_brl' => $this->normalizeNumericOrDefault($investmentSimulationRow['monthly_contribution_brl'] ?? null, 0.0),
            'period_months' => $this->normalizeIntegerOrDefault($investmentSimulationRow['period_months'] ?? null, 1),
            'rate_input_type' => $this->normalizeStringOrDefault($investmentSimulationRow['rate_input_type'] ?? null, 'MONTHLY'),
            'rate_value' => $this->normalizeNumericOrDefault($investmentSimulationRow['rate_value'] ?? null, 0.0),
            'effective_monthly_rate' => $this->normalizeNumericOrDefault($investmentSimulationRow['effective_monthly_rate'] ?? null, 0.0),
            'total_invested_brl' => $this->normalizeNumericOrDefault($investmentSimulationRow['total_invested_brl'] ?? null, 0.0),
            'total_yield_brl' => $this->normalizeNumericOrDefault($investmentSimulationRow['total_yield_brl'] ?? null, 0.0),
            'final_amount_brl' => $this->normalizeNumericOrDefault($investmentSimulationRow['final_amount_brl'] ?? null, 0.0),
            'created_at' => $this->normalizeDateTimeOrDefault($investmentSimulationRow['created_at'] ?? null, (new \DateTimeImmutable())->format('Y-m-d H:i:s')),
        ]);

        return (int) $this->connection->lastInsertId();
    }

    /**
     * @param array<int, int> $categoryMap
     * @param array<int, int> $bankAccountMap
     */
    private function insertInstallmentPlanRow(int $ownerId, array $installmentPlanRow, array $categoryMap, array $bankAccountMap): int
    {
        $nowLabel = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $this->connection->insert('finance_installment_plan', [
            'owner_id' => $ownerId,
            'category_id' => $this->mapOptionalForeignId($categoryMap, $installmentPlanRow['category_id'] ?? null),
            'default_bank_account_id' => $this->mapOptionalForeignId($bankAccountMap, $installmentPlanRow['default_bank_account_id'] ?? null),
            'direction' => $this->normalizeStringOrDefault($installmentPlanRow['direction'] ?? null, FinanceConstants::DIRECTION_PAYABLE),
            'title' => $this->normalizeStringOrDefault($installmentPlanRow['title'] ?? null, 'Parcelamento importado'),
            'total_amount_brl' => $this->normalizeNumericOrDefault($installmentPlanRow['total_amount_brl'] ?? null, 0.0),
            'down_payment_brl' => $this->normalizeNumericOrDefault($installmentPlanRow['down_payment_brl'] ?? null, 0.0),
            'installment_amount_brl' => $this->normalizeNumericOrDefault($installmentPlanRow['installment_amount_brl'] ?? null, 0.0),
            'installments_count' => $this->normalizeIntegerOrDefault($installmentPlanRow['installments_count'] ?? null, 1),
            'interest_amount_brl' => $this->normalizeNumericOrDefault($installmentPlanRow['interest_amount_brl'] ?? null, 0.0),
            'discount_amount_brl' => $this->normalizeNumericOrDefault($installmentPlanRow['discount_amount_brl'] ?? null, 0.0),
            'fine_amount_brl' => $this->normalizeNumericOrDefault($installmentPlanRow['fine_amount_brl'] ?? null, 0.0),
            'first_due_date' => $this->normalizeDateOrDefault($installmentPlanRow['first_due_date'] ?? null, (new \DateTimeImmutable('today'))->format('Y-m-d')),
            'status' => $this->normalizeStringOrDefault($installmentPlanRow['status'] ?? null, 'ACTIVE'),
            'created_at' => $this->normalizeDateTimeOrDefault($installmentPlanRow['created_at'] ?? null, $nowLabel),
            'updated_at' => $this->normalizeDateTimeOrDefault($installmentPlanRow['updated_at'] ?? null, $nowLabel),
            'deleted_at' => $this->normalizeNullableDateTime($installmentPlanRow['deleted_at'] ?? null),
        ]);

        return (int) $this->connection->lastInsertId();
    }

    /**
     * @param array<int, int> $recurringTypeMap
     * @param array<int, int> $categoryMap
     * @param array<int, int> $bankAccountMap
     */
    private function insertRecurringRuleRow(int $ownerId, array $recurringRuleRow, array $recurringTypeMap, array $categoryMap, array $bankAccountMap): int
    {
        $newRecurringTypeId = $this->mapRequiredForeignId($recurringTypeMap, $recurringRuleRow['recurring_type_id'] ?? null, 'finance_recurring_rule.recurring_type_id');

        $this->connection->insert('finance_recurring_rule', [
            'owner_id' => $ownerId,
            'recurring_type_id' => $newRecurringTypeId,
            'category_id' => $this->mapOptionalForeignId($categoryMap, $recurringRuleRow['category_id'] ?? null),
            'default_bank_account_id' => $this->mapOptionalForeignId($bankAccountMap, $recurringRuleRow['default_bank_account_id'] ?? null),
            'direction' => $this->normalizeStringOrDefault($recurringRuleRow['direction'] ?? null, FinanceConstants::DIRECTION_PAYABLE),
            'title' => $this->normalizeStringOrDefault($recurringRuleRow['title'] ?? null, 'Recorrência importada'),
            'description' => $this->normalizeNullableString($recurringRuleRow['description'] ?? null),
            'amount_brl' => $this->normalizeNumericOrDefault($recurringRuleRow['amount_brl'] ?? null, 0.0),
            'frequency' => $this->normalizeStringOrDefault($recurringRuleRow['frequency'] ?? null, 'MONTHLY'),
            'day_of_month' => $this->normalizeNullableInteger($recurringRuleRow['day_of_month'] ?? null),
            'starts_at' => $this->normalizeDateOrDefault($recurringRuleRow['starts_at'] ?? null, (new \DateTimeImmutable('today'))->format('Y-m-d')),
            'ends_at' => $this->normalizeNullableDate($recurringRuleRow['ends_at'] ?? null),
            'next_run_date' => $this->normalizeNullableDate($recurringRuleRow['next_run_date'] ?? null),
            'is_active' => FinanceInput::toDatabaseBoolean(FinanceInput::normalizeBoolean($recurringRuleRow['is_active'] ?? true, true)),
            'created_at' => $this->normalizeDateTimeOrDefault($recurringRuleRow['created_at'] ?? null, (new \DateTimeImmutable())->format('Y-m-d H:i:s')),
            'updated_at' => $this->normalizeDateTimeOrDefault($recurringRuleRow['updated_at'] ?? null, (new \DateTimeImmutable())->format('Y-m-d H:i:s')),
        ]);

        return (int) $this->connection->lastInsertId();
    }

    private function insertBankAccountRow(int $ownerId, array $bankAccountRow): int
    {
        $nowLabel = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $this->connection->insert('finance_bank_account', [
            'owner_id' => $ownerId,
            'name' => $this->normalizeStringOrDefault($bankAccountRow['name'] ?? null, 'Conta importada'),
            'bank_name' => $this->normalizeStringOrDefault($bankAccountRow['bank_name'] ?? null, $this->normalizeStringOrDefault($bankAccountRow['name'] ?? null, 'Banco importado')),
            'branch' => $this->normalizeNullableString($bankAccountRow['branch'] ?? null),
            'account_number' => $this->normalizeNullableString($bankAccountRow['account_number'] ?? null),
            'account_type' => $this->normalizeStringOrDefault($bankAccountRow['account_type'] ?? null, 'CHECKING'),
            'current_balance_brl' => $this->normalizeNumericOrDefault($bankAccountRow['current_balance_brl'] ?? null, 0.0),
            'color_hex' => $this->normalizeNullableString($bankAccountRow['color_hex'] ?? null),
            'icon_key' => $this->normalizeNullableString($bankAccountRow['icon_key'] ?? null),
            'is_active' => FinanceInput::toDatabaseBoolean(FinanceInput::normalizeBoolean($bankAccountRow['is_active'] ?? true, true)),
            'created_at' => $this->normalizeDateTimeOrDefault($bankAccountRow['created_at'] ?? null, $nowLabel),
            'updated_at' => $this->normalizeDateTimeOrDefault($bankAccountRow['updated_at'] ?? null, $nowLabel),
        ]);

        return (int) $this->connection->lastInsertId();
    }

    private function insertRecurringTypeRow(int $ownerId, array $recurringTypeRow): int
    {
        $nowLabel = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $this->connection->insert('finance_recurring_type', [
            'owner_id' => $ownerId,
            'name' => $this->normalizeStringOrDefault($recurringTypeRow['name'] ?? null, 'Tipo recorrente importado'),
            'normalized_name' => $this->normalizeStringOrDefault($recurringTypeRow['normalized_name'] ?? null, $this->buildNormalizedName($recurringTypeRow['name'] ?? null)),
            'description' => $this->normalizeNullableString($recurringTypeRow['description'] ?? null),
            'is_system' => FinanceInput::toDatabaseBoolean(FinanceInput::normalizeBoolean($recurringTypeRow['is_system'] ?? false, false)),
            'is_active' => FinanceInput::toDatabaseBoolean(FinanceInput::normalizeBoolean($recurringTypeRow['is_active'] ?? true, true)),
            'created_at' => $this->normalizeDateTimeOrDefault($recurringTypeRow['created_at'] ?? null, $nowLabel),
            'updated_at' => $this->normalizeDateTimeOrDefault($recurringTypeRow['updated_at'] ?? null, $nowLabel),
        ]);

        return (int) $this->connection->lastInsertId();
    }

    private function insertCategoryRow(int $ownerId, array $categoryRow): int
    {
        $nowLabel = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $this->connection->insert('finance_category', [
            'owner_id' => $ownerId,
            'name' => $this->normalizeStringOrDefault($categoryRow['name'] ?? null, 'Categoria importada'),
            'normalized_name' => $this->normalizeStringOrDefault($categoryRow['normalized_name'] ?? null, $this->buildNormalizedName($categoryRow['name'] ?? null)),
            'kind' => $this->normalizeStringOrDefault($categoryRow['kind'] ?? null, 'BOTH'),
            'is_system' => FinanceInput::toDatabaseBoolean(FinanceInput::normalizeBoolean($categoryRow['is_system'] ?? false, false)),
            'is_active' => FinanceInput::toDatabaseBoolean(FinanceInput::normalizeBoolean($categoryRow['is_active'] ?? true, true)),
            'created_at' => $this->normalizeDateTimeOrDefault($categoryRow['created_at'] ?? null, $nowLabel),
            'updated_at' => $this->normalizeDateTimeOrDefault($categoryRow['updated_at'] ?? null, $nowLabel),
        ]);

        return (int) $this->connection->lastInsertId();
    }

    private function deleteOwnerFinanceData(int $ownerId): void
    {
        $this->connection->executeStatement(<<<'SQL'
            DELETE FROM finance_external_connection
            WHERE owner_id = :ownerId
        SQL, ['ownerId' => $ownerId]);

        $this->connection->executeStatement(<<<'SQL'
            DELETE FROM finance_debt_plan
            WHERE owner_id = :ownerId
        SQL, ['ownerId' => $ownerId]);

        $this->connection->executeStatement(<<<'SQL'
            DELETE FROM finance_bank_account_ledger
            WHERE owner_id = :ownerId
        SQL, ['ownerId' => $ownerId]);

        $this->connection->executeStatement(<<<'SQL'
            DELETE FROM finance_entry_status_history
            WHERE owner_id = :ownerId
        SQL, ['ownerId' => $ownerId]);

        $this->connection->executeStatement(<<<'SQL'
            DELETE FROM finance_entry_settlement
            WHERE owner_id = :ownerId
        SQL, ['ownerId' => $ownerId]);

        $this->connection->executeStatement(<<<'SQL'
            DELETE FROM finance_entry
            WHERE owner_id = :ownerId
        SQL, ['ownerId' => $ownerId]);

        $this->connection->executeStatement(<<<'SQL'
            DELETE FROM finance_installment_item
            WHERE plan_id IN (
                SELECT id
                FROM finance_installment_plan
                WHERE owner_id = :ownerId
            )
        SQL, ['ownerId' => $ownerId]);

        $this->connection->executeStatement(<<<'SQL'
            DELETE FROM finance_recurring_rule_run
            WHERE recurring_rule_id IN (
                SELECT id
                FROM finance_recurring_rule
                WHERE owner_id = :ownerId
            )
        SQL, ['ownerId' => $ownerId]);

        $this->connection->executeStatement(<<<'SQL'
            DELETE FROM finance_investment_plan_run
            WHERE investment_plan_id IN (
                SELECT id
                FROM finance_investment_plan
                WHERE owner_id = :ownerId
            )
        SQL, ['ownerId' => $ownerId]);

        $this->connection->executeStatement(<<<'SQL'
            DELETE FROM finance_installment_plan
            WHERE owner_id = :ownerId
        SQL, ['ownerId' => $ownerId]);

        $this->connection->executeStatement(<<<'SQL'
            DELETE FROM finance_recurring_rule
            WHERE owner_id = :ownerId
        SQL, ['ownerId' => $ownerId]);

        $this->connection->executeStatement(<<<'SQL'
            DELETE FROM finance_investment_plan
            WHERE owner_id = :ownerId
        SQL, ['ownerId' => $ownerId]);

        $this->connection->executeStatement(<<<'SQL'
            DELETE FROM finance_investment_simulation_point
            WHERE simulation_id IN (
                SELECT id
                FROM finance_investment_simulation
                WHERE owner_id = :ownerId
            )
        SQL, ['ownerId' => $ownerId]);

        $this->connection->executeStatement(<<<'SQL'
            DELETE FROM finance_investment_simulation
            WHERE owner_id = :ownerId
        SQL, ['ownerId' => $ownerId]);

        $this->connection->executeStatement(<<<'SQL'
            DELETE FROM finance_bank_account
            WHERE owner_id = :ownerId
        SQL, ['ownerId' => $ownerId]);

        $this->connection->executeStatement(<<<'SQL'
            DELETE FROM finance_recurring_type
            WHERE owner_id = :ownerId
        SQL, ['ownerId' => $ownerId]);

        $this->connection->executeStatement(<<<'SQL'
            DELETE FROM finance_category
            WHERE owner_id = :ownerId
        SQL, ['ownerId' => $ownerId]);

        $this->connection->executeStatement(<<<'SQL'
            DELETE FROM finance_export_job
            WHERE owner_id = :ownerId
        SQL, ['ownerId' => $ownerId]);
    }

    /**
     * @return array<string, int>
     */
    private function buildImportCounters(): array
    {
        return [
            'categories' => 0,
            'recurringTypes' => 0,
            'bankAccounts' => 0,
            'recurringRules' => 0,
            'installmentPlans' => 0,
            'installmentItems' => 0,
            'investmentSimulations' => 0,
            'investmentSimulationPoints' => 0,
            'investmentPlans' => 0,
            'investmentPlanRuns' => 0,
            'externalConnections' => 0,
            'externalAccounts' => 0,
            'externalTransactions' => 0,
            'entries' => 0,
            'entrySettlements' => 0,
            'entryStatusHistory' => 0,
            'bankAccountLedger' => 0,
            'recurringRuleRuns' => 0,
            'debtPlans' => 0,
            'externalTransactionLinks' => 0,
        ];
    }

    private function registerMappedId(array &$idMap, array $sourceRow, int $newId): void
    {
        $oldId = (int) ($sourceRow['id'] ?? 0);
        if ($oldId <= 0) {
            return;
        }

        $idMap[$oldId] = $newId;
    }

    /**
     * @param array<int, int> $foreignMap
     */
    private function mapOptionalForeignId(array $foreignMap, mixed $oldId): ?int
    {
        $normalizedOldId = (int) $oldId;
        if ($normalizedOldId <= 0) {
            return null;
        }

        return $foreignMap[$normalizedOldId] ?? null;
    }

    /**
     * @param array<int, int> $foreignMap
     */
    private function mapRequiredForeignId(array $foreignMap, mixed $oldId, string $fieldLabel): int
    {
        $normalizedOldId = (int) $oldId;
        if ($normalizedOldId <= 0) {
            throw new \InvalidArgumentException(sprintf('Invalid required field "%s" in snapshot.', $fieldLabel));
        }

        $mappedId = $foreignMap[$normalizedOldId] ?? null;
        if ($mappedId === null || $mappedId <= 0) {
            throw new \InvalidArgumentException(sprintf('Failed to map required field "%s" during import.', $fieldLabel));
        }

        return $mappedId;
    }

    private function normalizeJsonString(mixed $jsonValue): string
    {
        if (is_string($jsonValue)) {
            $trimmedJsonValue = trim($jsonValue);
            if ($trimmedJsonValue === '') {
                return '{}';
            }

            json_decode($trimmedJsonValue, true);
            if (json_last_error() === \JSON_ERROR_NONE) {
                return $trimmedJsonValue;
            }

            return json_encode(['raw' => $trimmedJsonValue], \JSON_THROW_ON_ERROR);
        }

        if (is_array($jsonValue) || is_object($jsonValue)) {
            return json_encode($jsonValue, \JSON_THROW_ON_ERROR);
        }

        if ($jsonValue === null) {
            return '{}';
        }

        return json_encode(['raw' => $jsonValue], \JSON_THROW_ON_ERROR);
    }

    private function normalizeDateTimeOrDefault(mixed $rawDateTimeValue, string $defaultDateTimeValue): string
    {
        $normalizedDateTime = $this->normalizeNullableDateTime($rawDateTimeValue);
        if ($normalizedDateTime === null) {
            return $defaultDateTimeValue;
        }

        return $normalizedDateTime;
    }

    private function normalizeNullableDateTime(mixed $rawDateTimeValue): ?string
    {
        if ($rawDateTimeValue === null) {
            return null;
        }

        $normalizedDateTimeValue = trim((string) $rawDateTimeValue);
        if ($normalizedDateTimeValue === '') {
            return null;
        }

        return $normalizedDateTimeValue;
    }

    private function normalizeDateOrDefault(mixed $rawDateValue, string $defaultDateValue): string
    {
        $normalizedDate = $this->normalizeNullableDate($rawDateValue);
        if ($normalizedDate === null) {
            return $defaultDateValue;
        }

        return $normalizedDate;
    }

    private function normalizeNullableDate(mixed $rawDateValue): ?string
    {
        if ($rawDateValue === null) {
            return null;
        }

        $normalizedDateValue = trim((string) $rawDateValue);
        if ($normalizedDateValue === '') {
            return null;
        }

        return $normalizedDateValue;
    }

    private function normalizeStringOrDefault(mixed $rawValue, string $defaultValue): string
    {
        $normalizedValue = trim((string) $rawValue);

        return $normalizedValue !== '' ? $normalizedValue : $defaultValue;
    }

    private function normalizeNullableString(mixed $rawValue): ?string
    {
        if ($rawValue === null) {
            return null;
        }

        $normalizedValue = trim((string) $rawValue);

        return $normalizedValue !== '' ? $normalizedValue : null;
    }

    private function normalizeNumericOrDefault(mixed $rawValue, float $defaultValue): float
    {
        if ($rawValue === null || $rawValue === '') {
            return $defaultValue;
        }

        return (float) $rawValue;
    }

    private function normalizeNullableNumeric(mixed $rawValue): ?float
    {
        if ($rawValue === null || $rawValue === '') {
            return null;
        }

        return (float) $rawValue;
    }

    private function normalizeIntegerOrDefault(mixed $rawValue, int $defaultValue): int
    {
        if ($rawValue === null || $rawValue === '') {
            return $defaultValue;
        }

        return (int) $rawValue;
    }

    private function normalizeNullableInteger(mixed $rawValue): ?int
    {
        if ($rawValue === null || $rawValue === '') {
            return null;
        }

        return (int) $rawValue;
    }

    private function buildNormalizedName(mixed $rawName): string
    {
        $nameLabel = trim((string) $rawName);
        if ($nameLabel === '') {
            $nameLabel = 'importado';
        }

        return FinanceInput::normalizeNameKey($nameLabel);
    }

    /**
     * @param array<string, mixed> $snapshotData
     *
     * @return list<array<string, mixed>>
     */
    private function readSnapshotRows(array $snapshotData, string $sectionKey): array
    {
        $rows = $snapshotData[$sectionKey] ?? [];
        if (!is_array($rows)) {
            return [];
        }

        $normalizedRows = [];
        foreach ($rows as $rowItem) {
            if (is_array($rowItem)) {
                $normalizedRows[] = $rowItem;
            }
        }

        return $normalizedRows;
    }

    private function resolveProviderIdBySnapshotRow(array $externalConnectionRow): int
    {
        $providerCode = strtoupper(trim((string) ($externalConnectionRow['provider_code'] ?? '')));
        if ($providerCode !== '') {
            $foundProviderId = $this->connection->fetchOne(<<<'SQL'
                SELECT id
                FROM finance_external_provider
                WHERE provider_code = :providerCode
                LIMIT 1
            SQL, [
                'providerCode' => $providerCode,
            ]);

            if ($foundProviderId !== false) {
                return (int) $foundProviderId;
            }
        }

        $providerId = (int) ($externalConnectionRow['provider_id'] ?? 0);
        if ($providerId > 0) {
            $exists = $this->connection->fetchOne(<<<'SQL'
                SELECT id
                FROM finance_external_provider
                WHERE id = :providerId
                LIMIT 1
            SQL, [
                'providerId' => $providerId,
            ]);

            if ($exists !== false) {
                return (int) $exists;
            }
        }

        throw new \InvalidArgumentException('Open Finance provider not found while importing connection.');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchOwnerTableRows(string $tableName, int $ownerId): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->connection->fetchAllAssociative(sprintf(
            'SELECT * FROM %s WHERE owner_id = :ownerId ORDER BY id ASC',
            $tableName,
        ), [
            'ownerId' => $ownerId,
        ]);

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchInstallmentItemsRows(int $ownerId): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT item.*
            FROM finance_installment_item item
            INNER JOIN finance_installment_plan plan ON plan.id = item.plan_id
            WHERE plan.owner_id = :ownerId
            ORDER BY item.id ASC
        SQL, [
            'ownerId' => $ownerId,
        ]);

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchRecurringRuleRunsRows(int $ownerId): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT run.*
            FROM finance_recurring_rule_run run
            INNER JOIN finance_recurring_rule rule ON rule.id = run.recurring_rule_id
            WHERE rule.owner_id = :ownerId
            ORDER BY run.id ASC
        SQL, [
            'ownerId' => $ownerId,
        ]);

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchInvestmentSimulationPointsRows(int $ownerId): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT point.*
            FROM finance_investment_simulation_point point
            INNER JOIN finance_investment_simulation simulation ON simulation.id = point.simulation_id
            WHERE simulation.owner_id = :ownerId
            ORDER BY point.id ASC
        SQL, [
            'ownerId' => $ownerId,
        ]);

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchInvestmentPlanRunsRows(int $ownerId): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT run.*
            FROM finance_investment_plan_run run
            INNER JOIN finance_investment_plan plan ON plan.id = run.investment_plan_id
            WHERE plan.owner_id = :ownerId
            ORDER BY run.id ASC
        SQL, [
            'ownerId' => $ownerId,
        ]);

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchExternalConnectionsRows(int $ownerId): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT
                connection.*,
                provider.provider_code
            FROM finance_external_connection connection
            INNER JOIN finance_external_provider provider ON provider.id = connection.provider_id
            WHERE connection.owner_id = :ownerId
            ORDER BY connection.id ASC
        SQL, [
            'ownerId' => $ownerId,
        ]);

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchExternalAccountsRows(int $ownerId): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT account.*
            FROM finance_external_account account
            INNER JOIN finance_external_connection connection ON connection.id = account.connection_id
            WHERE connection.owner_id = :ownerId
            ORDER BY account.id ASC
        SQL, [
            'ownerId' => $ownerId,
        ]);

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchExternalTransactionsRows(int $ownerId): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT transaction.*
            FROM finance_external_transaction transaction
            INNER JOIN finance_external_account account ON account.id = transaction.external_account_id
            INNER JOIN finance_external_connection connection ON connection.id = account.connection_id
            WHERE connection.owner_id = :ownerId
            ORDER BY transaction.id ASC
        SQL, [
            'ownerId' => $ownerId,
        ]);

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchExternalTransactionLinksRows(int $ownerId): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT link.*
            FROM finance_external_transaction_link link
            INNER JOIN finance_external_transaction transaction ON transaction.id = link.external_transaction_id
            INNER JOIN finance_external_account account ON account.id = transaction.external_account_id
            INNER JOIN finance_external_connection connection ON connection.id = account.connection_id
            WHERE connection.owner_id = :ownerId
            ORDER BY link.id ASC
        SQL, [
            'ownerId' => $ownerId,
        ]);

        return $rows;
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
