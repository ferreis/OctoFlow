<?php

namespace App\Finance;

use App\Entity\User;
use Doctrine\DBAL\Connection;

final class FinanceEntryService
{
    public function __construct(
        private readonly Connection $connection,
        private readonly FinanceCatalogService $financeCatalogService,
    ) {
    }

    /**
     * @param array<string, mixed> $filters
     *
     * @return array{items: list<array<string, mixed>>, meta: array<string, int>}
     */
    public function listEntries(User $user, array $filters = []): array
    {
        $ownerId = $this->requireOwnerId($user);
        $pagination = FinanceInput::normalizePagination($filters['page'] ?? 1, $filters['itemsPerPage'] ?? 10);

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

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $whereParts[] = "(LOWER(entry.title) LIKE :search OR LOWER(COALESCE(entry.description, '')) LIKE :search)";
            $parameters['search'] = '%' . mb_strtolower($search) . '%';
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

        $whereSql = implode(' AND ', $whereParts);

        $total = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM finance_entry entry WHERE ' . $whereSql,
            $parameters,
        );

        $parameters['limit'] = $pagination['itemsPerPage'];
        $parameters['offset'] = $pagination['offset'];

        /** @var list<array<string, mixed>> $items */
        $items = $this->connection->fetchAllAssociative(
            'SELECT
                entry.id,
                entry.direction,
                entry.entry_type AS "entryType",
                entry.status,
                entry.title,
                entry.description,
                entry.due_date AS "dueDate",
                entry.competence_month AS "competenceMonth",
                entry.expected_amount_brl AS "expectedAmountBrl",
                entry.settled_amount_brl AS "settledAmountBrl",
                entry.remaining_amount_brl AS "remainingAmountBrl",
                entry.source_origin AS "sourceOrigin",
                entry.source_system AS "sourceSystem",
                entry.fully_settled_at AS "fullySettledAt",
                entry.created_at AS "createdAt",
                entry.updated_at AS "updatedAt",
                category.id AS "categoryId",
                category.name AS "categoryName",
                bank_account.id AS "bankAccountId",
                bank_account.name AS "bankAccountName"
            FROM finance_entry entry
            LEFT JOIN finance_category category ON category.id = entry.category_id
            LEFT JOIN finance_bank_account bank_account ON bank_account.id = entry.bank_account_id
            WHERE ' . $whereSql . '
            ORDER BY entry.due_date ASC NULLS LAST, entry.id DESC
            LIMIT :limit OFFSET :offset',
            $parameters,
        );

        return [
            'items' => $items,
            'meta' => [
                'page' => $pagination['page'],
                'itemsPerPage' => $pagination['itemsPerPage'],
                'total' => $total,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function createEntry(User $user, array $payload): array
    {
        $ownerId = $this->requireOwnerId($user);
        $direction = FinanceInput::normalizeDirection($payload['direction'] ?? '');
        $title = FinanceInput::normalizeName((string) ($payload['title'] ?? ''));
        $description = trim((string) ($payload['description'] ?? ''));
        $entryType = strtoupper(trim((string) ($payload['entryType'] ?? FinanceConstants::ENTRY_TYPE_ONE_OFF)));

        if ($title === '') {
            throw new \InvalidArgumentException('The entry title is required.');
        }

        if (!in_array($entryType, FinanceConstants::ENTRY_TYPES, true)) {
            throw new \InvalidArgumentException('The entry type is invalid.');
        }

        $dueDate = FinanceInput::normalizeOptionalDate($payload['dueDate'] ?? null);
        $competenceMonth = FinanceInput::normalizeOptionalDate($payload['competenceMonth'] ?? null);
        if (!$competenceMonth instanceof \DateTimeImmutable) {
            $sourceDate = $dueDate ?? new \DateTimeImmutable('today');
            $competenceMonth = new \DateTimeImmutable($sourceDate->format('Y-m-01'));
        }

        $expectedAmountBrl = FinanceInput::normalizeMoney($payload['expectedAmountBrl'] ?? 0, 'expectedAmountBrl');
        if ($expectedAmountBrl <= 0) {
            throw new \InvalidArgumentException('The expected amount must be greater than zero.');
        }

        $inputCurrencyCode = strtoupper(trim((string) ($payload['inputCurrencyCode'] ?? 'BRL')));
        if ($inputCurrencyCode === '') {
            $inputCurrencyCode = 'BRL';
        }

        $inputAmount = FinanceInput::normalizeOptionalMoney($payload['inputAmount'] ?? null, $expectedAmountBrl);
        $fxRateToBrl = FinanceInput::normalizeOptionalMoney($payload['fxRateToBrl'] ?? null, 1.0);
        $fxRateDate = FinanceInput::normalizeOptionalDate($payload['fxRateDate'] ?? null);

        if ($inputCurrencyCode !== 'BRL' && ($fxRateToBrl === null || $fxRateToBrl <= 0)) {
            throw new \InvalidArgumentException('The FX rate must be informed for non-BRL entries.');
        }

        $categoryId = $this->normalizeOwnedCategoryId($ownerId, $payload['categoryId'] ?? null);
        $bankAccountId = $this->normalizeOwnedBankAccountId($ownerId, $payload['bankAccountId'] ?? null, false);

        $status = FinanceInput::normalizeStatus($direction, $payload['status'] ?? null, true);
        if ($status === null) {
            $status = FinanceInput::defaultStatusByDirection($direction, $dueDate);
        }

        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $this->connection->insert('finance_entry', [
            'owner_id' => $ownerId,
            'category_id' => $categoryId,
            'bank_account_id' => $bankAccountId,
            'direction' => $direction,
            'entry_type' => $entryType,
            'status' => $status,
            'title' => $title,
            'description' => $description !== '' ? $description : null,
            'due_date' => $dueDate?->format('Y-m-d'),
            'competence_month' => $competenceMonth->format('Y-m-d'),
            'expected_amount_brl' => $expectedAmountBrl,
            'settled_amount_brl' => 0,
            'remaining_amount_brl' => $expectedAmountBrl,
            'input_currency_code' => $inputCurrencyCode,
            'input_amount' => $inputAmount,
            'fx_rate_to_brl' => $fxRateToBrl,
            'fx_rate_date' => $fxRateDate?->format('Y-m-d'),
            'source_origin' => strtoupper(trim((string) ($payload['sourceOrigin'] ?? FinanceConstants::SOURCE_ORIGIN_MANUAL))),
            'source_system' => trim((string) ($payload['sourceSystem'] ?? 'MANUAL_ENTRY')),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $createdId = (int) $this->connection->lastInsertId();

        $createdEntry = $this->getEntryById($ownerId, $createdId);
        $this->appendEntryStatusHistory($ownerId, $createdId, null, (string) $createdEntry['status'], 'CREATED', null);

        return $createdEntry;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function updateEntry(User $user, int $entryId, array $payload): array
    {
        $ownerId = $this->requireOwnerId($user);
        $existingEntry = $this->getEntryById($ownerId, $entryId);

        $direction = FinanceInput::normalizeDirection($payload['direction'] ?? $existingEntry['direction']);
        $title = array_key_exists('title', $payload)
            ? FinanceInput::normalizeName((string) $payload['title'])
            : (string) $existingEntry['title'];
        $description = array_key_exists('description', $payload)
            ? trim((string) $payload['description'])
            : trim((string) ($existingEntry['description'] ?? ''));
        $entryType = array_key_exists('entryType', $payload)
            ? strtoupper(trim((string) $payload['entryType']))
            : (string) $existingEntry['entryType'];

        if ($title === '') {
            throw new \InvalidArgumentException('The entry title is required.');
        }

        if (!in_array($entryType, FinanceConstants::ENTRY_TYPES, true)) {
            throw new \InvalidArgumentException('The entry type is invalid.');
        }

        $dueDate = array_key_exists('dueDate', $payload)
            ? FinanceInput::normalizeOptionalDate($payload['dueDate'])
            : FinanceInput::normalizeOptionalDate($existingEntry['dueDate'] ?? null);
        $competenceMonth = array_key_exists('competenceMonth', $payload)
            ? FinanceInput::normalizeOptionalDate($payload['competenceMonth'])
            : FinanceInput::normalizeOptionalDate($existingEntry['competenceMonth'] ?? null);

        if (!$competenceMonth instanceof \DateTimeImmutable) {
            $competenceMonth = new \DateTimeImmutable(($dueDate ?? new \DateTimeImmutable('today'))->format('Y-m-01'));
        }

        $expectedAmountBrl = array_key_exists('expectedAmountBrl', $payload)
            ? FinanceInput::normalizeMoney($payload['expectedAmountBrl'], 'expectedAmountBrl')
            : (float) $existingEntry['expectedAmountBrl'];
        if ($expectedAmountBrl <= 0) {
            throw new \InvalidArgumentException('The expected amount must be greater than zero.');
        }

        $settledAmountBrl = (float) $existingEntry['settledAmountBrl'];
        if ($settledAmountBrl > $expectedAmountBrl) {
            throw new \InvalidArgumentException('The expected amount cannot be lower than the already settled amount.');
        }

        $remainingAmountBrl = max(0, round($expectedAmountBrl - $settledAmountBrl, 2));

        $inputCurrencyCode = strtoupper(trim((string) ($payload['inputCurrencyCode'] ?? $existingEntry['inputCurrencyCode'] ?? 'BRL')));
        $inputAmount = array_key_exists('inputAmount', $payload)
            ? FinanceInput::normalizeOptionalMoney($payload['inputAmount'], $expectedAmountBrl)
            : FinanceInput::normalizeOptionalMoney($existingEntry['inputAmount'] ?? null, $expectedAmountBrl);
        $fxRateToBrl = array_key_exists('fxRateToBrl', $payload)
            ? FinanceInput::normalizeOptionalMoney($payload['fxRateToBrl'], 1.0)
            : FinanceInput::normalizeOptionalMoney($existingEntry['fxRateToBrl'] ?? null, 1.0);
        $fxRateDate = array_key_exists('fxRateDate', $payload)
            ? FinanceInput::normalizeOptionalDate($payload['fxRateDate'])
            : FinanceInput::normalizeOptionalDate($existingEntry['fxRateDate'] ?? null);

        if ($inputCurrencyCode !== 'BRL' && ($fxRateToBrl === null || $fxRateToBrl <= 0)) {
            throw new \InvalidArgumentException('The FX rate must be informed for non-BRL entries.');
        }

        $categoryId = array_key_exists('categoryId', $payload)
            ? $this->normalizeOwnedCategoryId($ownerId, $payload['categoryId'])
            : $this->normalizeOwnedCategoryId($ownerId, $existingEntry['categoryId'] ?? null);
        $bankAccountId = array_key_exists('bankAccountId', $payload)
            ? $this->normalizeOwnedBankAccountId($ownerId, $payload['bankAccountId'], false)
            : $this->normalizeOwnedBankAccountId($ownerId, $existingEntry['bankAccountId'] ?? null, false);

        $manualStatus = FinanceInput::normalizeStatus($direction, $payload['status'] ?? null, true);
        $recalculatedStatus = $this->resolveStatus(
            $direction,
            $dueDate,
            $expectedAmountBrl,
            $settledAmountBrl,
            $remainingAmountBrl,
            $manualStatus,
        );

        $previousStatus = (string) $existingEntry['status'];

        $this->connection->update('finance_entry', [
            'category_id' => $categoryId,
            'bank_account_id' => $bankAccountId,
            'direction' => $direction,
            'entry_type' => $entryType,
            'status' => $recalculatedStatus,
            'title' => $title,
            'description' => $description !== '' ? $description : null,
            'due_date' => $dueDate?->format('Y-m-d'),
            'competence_month' => $competenceMonth->format('Y-m-d'),
            'expected_amount_brl' => $expectedAmountBrl,
            'remaining_amount_brl' => $remainingAmountBrl,
            'input_currency_code' => $inputCurrencyCode,
            'input_amount' => $inputAmount,
            'fx_rate_to_brl' => $fxRateToBrl,
            'fx_rate_date' => $fxRateDate?->format('Y-m-d'),
            'updated_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ], [
            'id' => $entryId,
            'owner_id' => $ownerId,
        ]);

        if ($previousStatus !== $recalculatedStatus) {
            $this->appendEntryStatusHistory($ownerId, $entryId, $previousStatus, $recalculatedStatus, 'UPDATED', null);
        }

        return $this->getEntryById($ownerId, $entryId);
    }

    /**
     * @return array<string, mixed>
     */
    public function softDeleteEntry(User $user, int $entryId): array
    {
        $ownerId = $this->requireOwnerId($user);

        return $this->connection->transactional(function () use ($ownerId, $entryId): array {
            $entry = $this->getEntryById($ownerId, $entryId);
            $previousStatus = (string) ($entry['status'] ?? '');

            /** @var list<array<string, mixed>> $settlements */
            $settlements = $this->connection->fetchAllAssociative(<<<'SQL'
                SELECT id, bank_account_id, amount_brl, settled_at
                FROM finance_entry_settlement
                WHERE owner_id = :ownerId
                  AND entry_id = :entryId
                ORDER BY settled_at ASC, id ASC
            SQL, [
                'ownerId' => $ownerId,
                'entryId' => $entryId,
            ]);

            foreach ($settlements as $settlement) {
                $settlementBankAccountId = isset($settlement['bank_account_id']) ? (int) $settlement['bank_account_id'] : null;
                if ($settlementBankAccountId === null || $settlementBankAccountId <= 0) {
                    continue;
                }

                $settlementDate = new \DateTimeImmutable((string) $settlement['settled_at']);
                $this->applyBankAccountBalanceChange(
                    $ownerId,
                    $settlementBankAccountId,
                    $entryId,
                    (int) $settlement['id'],
                    (string) $entry['direction'],
                    (float) $settlement['amount_brl'],
                    $settlementDate,
                    true,
                );
            }

            $this->connection->delete('finance_entry_settlement', [
                'owner_id' => $ownerId,
                'entry_id' => $entryId,
            ]);

            $deletedAt = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

            $this->connection->update('finance_entry', [
                'status' => 'CANCELED',
                'settled_amount_brl' => 0,
                'remaining_amount_brl' => 0,
                'fully_settled_at' => null,
                'updated_at' => $deletedAt,
                'deleted_at' => $deletedAt,
            ], [
                'id' => $entryId,
                'owner_id' => $ownerId,
            ]);

            $this->appendEntryStatusHistory(
                $ownerId,
                $entryId,
                $previousStatus !== '' ? $previousStatus : null,
                'CANCELED',
                'SOFT_DELETED',
                'Entry soft deleted by user action.',
            );

            return [
                'id' => $entryId,
                'status' => 'CANCELED',
                'deletedAt' => $deletedAt,
            ];
        });
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array{entry: array<string, mixed>, settlement: array<string, mixed>}
     */
    public function settleEntry(User $user, int $entryId, array $payload): array
    {
        $ownerId = $this->requireOwnerId($user);

        return $this->connection->transactional(function () use ($ownerId, $entryId, $payload): array {
            $entry = $this->getEntryById($ownerId, $entryId);
            $currentStatus = (string) $entry['status'];
            if (in_array($currentStatus, ['CANCELED', 'NEGOTIATED'], true)) {
                throw new \InvalidArgumentException('Canceled or negotiated entries cannot receive settlements.');
            }

            $remainingAmountBrl = (float) $entry['remainingAmountBrl'];
            if ($remainingAmountBrl <= 0.00001) {
                throw new \InvalidArgumentException('This entry is already fully settled.');
            }

            $settlementAmountBrl = FinanceInput::normalizeMoney($payload['amountBrl'] ?? 0, 'amountBrl');
            if ($settlementAmountBrl <= 0) {
                throw new \InvalidArgumentException('The settlement amount must be greater than zero.');
            }

            if ($settlementAmountBrl - $remainingAmountBrl > 0.009) {
                throw new \InvalidArgumentException('The settlement amount cannot exceed the remaining amount.');
            }

            $settlementDate = FinanceInput::normalizeOptionalDate($payload['settledAt'] ?? null) ?? new \DateTimeImmutable();
            $settlementType = strtoupper(trim((string) ($payload['settlementType'] ?? '')));
            if ($settlementType === '') {
                $settlementType = ((string) $entry['direction']) === FinanceConstants::DIRECTION_PAYABLE
                    ? 'PAYMENT'
                    : 'RECEIPT';
            }

            if (!in_array($settlementType, ['PAYMENT', 'RECEIPT', 'TRANSFER', 'ADJUSTMENT'], true)) {
                throw new \InvalidArgumentException('The settlement type is invalid.');
            }

            $bankAccountId = $this->normalizeOwnedBankAccountId(
                $ownerId,
                $payload['bankAccountId'] ?? $entry['bankAccountId'] ?? null,
                true,
            );

            $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
            $this->connection->insert('finance_entry_settlement', [
                'owner_id' => $ownerId,
                'entry_id' => $entryId,
                'bank_account_id' => $bankAccountId,
                'settlement_type' => $settlementType,
                'amount_brl' => $settlementAmountBrl,
                'settled_at' => $settlementDate->format('Y-m-d H:i:s'),
                'note' => trim((string) ($payload['note'] ?? '')) ?: null,
                'created_at' => $now,
            ]);

            $settlementId = (int) $this->connection->lastInsertId();

            if ($bankAccountId !== null) {
                $this->applyBankAccountBalanceChange(
                    $ownerId,
                    $bankAccountId,
                    $entryId,
                    $settlementId,
                    (string) $entry['direction'],
                    $settlementAmountBrl,
                    $settlementDate,
                    false,
                );
            }

            $updatedEntry = $this->recalculateEntrySettlement($ownerId, $entryId, 'SETTLED');

            /** @var array<string, mixed>|false $createdSettlement */
            $createdSettlement = $this->connection->fetchAssociative(<<<'SQL'
                SELECT
                    id,
                    entry_id AS "entryId",
                    bank_account_id AS "bankAccountId",
                    settlement_type AS "settlementType",
                    amount_brl AS "amountBrl",
                    settled_at AS "settledAt",
                    note,
                    created_at AS "createdAt"
                FROM finance_entry_settlement
                WHERE id = :settlementId
                LIMIT 1
            SQL, ['settlementId' => $settlementId]);

            if (!is_array($createdSettlement)) {
                throw new \RuntimeException('Failed to read created settlement.');
            }

            return [
                'entry' => $updatedEntry,
                'settlement' => $createdSettlement,
            ];
        });
    }

    public function refreshOverdueStatusesForAllUsers(): int
    {
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        /** @var list<array<string, mixed>> $entries */
        $entries = $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT id, owner_id, status
            FROM finance_entry
            WHERE due_date < :today
              AND deleted_at IS NULL
              AND remaining_amount_brl > 0
              AND status NOT IN ('OVERDUE', 'CANCELED', 'NEGOTIATED', 'PAID', 'RECEIVED')
        SQL, [
            'today' => $today,
        ]);

        foreach ($entries as $entry) {
            $entryId = (int) $entry['id'];
            $ownerId = (int) $entry['owner_id'];
            $previousStatus = (string) $entry['status'];

            $this->connection->update('finance_entry', [
                'status' => 'OVERDUE',
                'updated_at' => $now,
            ], [
                'id' => $entryId,
                'owner_id' => $ownerId,
            ]);

            $this->appendEntryStatusHistory($ownerId, $entryId, $previousStatus, 'OVERDUE', 'AUTO_OVERDUE', null);
        }

        return count($entries);
    }

    /**
     * @return array<string, mixed>
     */
    public function getEntryById(int $ownerId, int $entryId): array
    {
        /** @var array<string, mixed>|false $entry */
        $entry = $this->connection->fetchAssociative(<<<'SQL'
            SELECT
                entry.id,
                entry.direction,
                entry.entry_type AS "entryType",
                entry.status,
                entry.title,
                entry.description,
                entry.due_date AS "dueDate",
                entry.competence_month AS "competenceMonth",
                entry.expected_amount_brl AS "expectedAmountBrl",
                entry.settled_amount_brl AS "settledAmountBrl",
                entry.remaining_amount_brl AS "remainingAmountBrl",
                entry.input_currency_code AS "inputCurrencyCode",
                entry.input_amount AS "inputAmount",
                entry.fx_rate_to_brl AS "fxRateToBrl",
                entry.fx_rate_date AS "fxRateDate",
                entry.source_origin AS "sourceOrigin",
                entry.source_system AS "sourceSystem",
                entry.fully_settled_at AS "fullySettledAt",
                entry.created_at AS "createdAt",
                entry.updated_at AS "updatedAt",
                entry.category_id AS "categoryId",
                category.name AS "categoryName",
                entry.bank_account_id AS "bankAccountId",
                bank_account.name AS "bankAccountName"
            FROM finance_entry entry
            LEFT JOIN finance_category category ON category.id = entry.category_id
            LEFT JOIN finance_bank_account bank_account ON bank_account.id = entry.bank_account_id
            WHERE entry.owner_id = :ownerId
              AND entry.id = :entryId
              AND entry.deleted_at IS NULL
            LIMIT 1
        SQL, [
            'ownerId' => $ownerId,
            'entryId' => $entryId,
        ]);

        if (!is_array($entry)) {
            throw new \InvalidArgumentException('Entry not found.');
        }

        return $entry;
    }

    /**
     * @return array<string, mixed>
     */
    public function recalculateEntrySettlement(int $ownerId, int $entryId, string $reasonCode): array
    {
        $entry = $this->getEntryById($ownerId, $entryId);

        $totalSettledAmount = (float) $this->connection->fetchOne(<<<'SQL'
            SELECT COALESCE(SUM(amount_brl), 0)
            FROM finance_entry_settlement
            WHERE owner_id = :ownerId
              AND entry_id = :entryId
        SQL, [
            'ownerId' => $ownerId,
            'entryId' => $entryId,
        ]);

        $expectedAmount = (float) $entry['expectedAmountBrl'];
        $remainingAmount = max(0, round($expectedAmount - $totalSettledAmount, 2));
        $dueDate = FinanceInput::normalizeOptionalDate($entry['dueDate'] ?? null);

        $previousStatus = (string) $entry['status'];
        $recalculatedStatus = $this->resolveStatus(
            (string) $entry['direction'],
            $dueDate,
            $expectedAmount,
            $totalSettledAmount,
            $remainingAmount,
            null,
        );

        $fullySettledAt = null;
        if ($remainingAmount <= 0.00001) {
            /** @var string|false $latestSettlementDate */
            $latestSettlementDate = $this->connection->fetchOne(<<<'SQL'
                SELECT MAX(settled_at)
                FROM finance_entry_settlement
                WHERE owner_id = :ownerId
                  AND entry_id = :entryId
            SQL, [
                'ownerId' => $ownerId,
                'entryId' => $entryId,
            ]);

            if (is_string($latestSettlementDate) && trim($latestSettlementDate) !== '') {
                $fullySettledAt = $latestSettlementDate;
            }
        }

        $this->connection->update('finance_entry', [
            'settled_amount_brl' => round($totalSettledAmount, 2),
            'remaining_amount_brl' => $remainingAmount,
            'status' => $recalculatedStatus,
            'fully_settled_at' => $fullySettledAt,
            'updated_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ], [
            'id' => $entryId,
            'owner_id' => $ownerId,
        ]);

        if ($previousStatus !== $recalculatedStatus) {
            $this->appendEntryStatusHistory($ownerId, $entryId, $previousStatus, $recalculatedStatus, $reasonCode, null);
        }

        return $this->getEntryById($ownerId, $entryId);
    }

    private function resolveStatus(
        string $direction,
        ?\DateTimeImmutable $dueDate,
        float $expectedAmount,
        float $settledAmount,
        float $remainingAmount,
        ?string $manualStatus,
    ): string {
        if (in_array($manualStatus, ['CANCELED', 'NEGOTIATED'], true)) {
            return $manualStatus;
        }

        if ($remainingAmount <= 0.00001) {
            return $direction === FinanceConstants::DIRECTION_PAYABLE ? 'PAID' : 'RECEIVED';
        }

        if ($settledAmount > 0.00001 && $remainingAmount > 0.00001 && $settledAmount < $expectedAmount) {
            return 'PARTIAL';
        }

        if ($dueDate instanceof \DateTimeImmutable && $dueDate < new \DateTimeImmutable('today')) {
            return 'OVERDUE';
        }

        if ($direction === FinanceConstants::DIRECTION_PAYABLE) {
            if ($dueDate instanceof \DateTimeImmutable && $dueDate > new \DateTimeImmutable('today')) {
                return 'SCHEDULED';
            }

            return 'PENDING';
        }

        return 'FORECAST';
    }

    private function appendEntryStatusHistory(
        int $ownerId,
        int $entryId,
        ?string $fromStatus,
        string $toStatus,
        string $reasonCode,
        ?string $reasonText,
    ): void {
        $this->connection->insert('finance_entry_status_history', [
            'owner_id' => $ownerId,
            'entry_id' => $entryId,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'reason_code' => $reasonCode,
            'reason_text' => $reasonText,
            'changed_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }

    private function applyBankAccountBalanceChange(
        int $ownerId,
        int $bankAccountId,
        int $entryId,
        int $settlementId,
        string $direction,
        float $amount,
        \DateTimeImmutable $happenedAt,
        bool $isReversal,
    ): void {
        $bankAccount = $this->financeCatalogService->getBankAccountById($ownerId, $bankAccountId);
        $currentBalance = (float) $bankAccount['currentBalanceBrl'];

        $isPayableEntry = $direction === FinanceConstants::DIRECTION_PAYABLE;
        $shouldDebit = $isPayableEntry xor $isReversal;

        $movementType = $shouldDebit ? 'DEBIT' : 'CREDIT';
        $newBalance = $shouldDebit
            ? round($currentBalance - $amount, 2)
            : round($currentBalance + $amount, 2);

        $this->connection->update('finance_bank_account', [
            'current_balance_brl' => $newBalance,
            'updated_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ], [
            'id' => $bankAccountId,
            'owner_id' => $ownerId,
        ]);

        $this->connection->insert('finance_bank_account_ledger', [
            'owner_id' => $ownerId,
            'bank_account_id' => $bankAccountId,
            'entry_id' => $entryId,
            'settlement_id' => $settlementId,
            'movement_type' => $movementType,
            'amount_brl' => $amount,
            'balance_after_brl' => $newBalance,
            'happened_at' => $happenedAt->format('Y-m-d H:i:s'),
            'source_type' => $isReversal ? 'SETTLEMENT_REVERSAL' : 'SETTLEMENT',
        ]);
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

    private function normalizeOwnedBankAccountId(int $ownerId, mixed $bankAccountId, bool $allowNull): ?int
    {
        $normalizedBankAccountId = (int) $bankAccountId;
        if ($normalizedBankAccountId <= 0) {
            if ($allowNull) {
                return null;
            }

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
            throw new \InvalidArgumentException('Inactive bank accounts cannot be used in new entries or settlements.');
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
