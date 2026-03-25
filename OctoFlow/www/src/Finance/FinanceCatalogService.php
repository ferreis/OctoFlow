<?php

namespace App\Finance;

use App\Entity\User;
use Doctrine\DBAL\Connection;

final class FinanceCatalogService
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * @return array{items: list<array<string, mixed>>}
     */
    public function listCategories(User $user, array $filters = []): array
    {
        $ownerId = $this->requireOwnerId($user);
        $kindFilter = strtoupper(trim((string) ($filters['kind'] ?? '')));

        $sql = <<<'SQL'
            SELECT
                id,
                name,
                normalized_name AS "normalizedName",
                kind,
                is_system AS "isSystem",
                is_active AS "isActive",
                created_at AS "createdAt",
                updated_at AS "updatedAt"
            FROM finance_category
            WHERE owner_id = :ownerId
        SQL;

        $parameters = ['ownerId' => $ownerId];

        if ($kindFilter !== '') {
            $sql .= ' AND kind = :kind';
            $parameters['kind'] = $kindFilter;
        }

        $sql .= ' ORDER BY is_system DESC, name ASC';

        /** @var list<array<string, mixed>> $items */
        $items = $this->connection->fetchAllAssociative($sql, $parameters);

        return ['items' => $items];
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function createCategory(User $user, array $payload): array
    {
        $ownerId = $this->requireOwnerId($user);
        $name = FinanceInput::normalizeName((string) ($payload['name'] ?? ''));
        $kind = strtoupper(trim((string) ($payload['kind'] ?? 'BOTH')));
        $isSystem = FinanceInput::normalizeBoolean($payload['isSystem'] ?? false, false);

        if ($name === '') {
            throw new \InvalidArgumentException('The category name is required.');
        }

        if (!in_array($kind, ['BOTH', 'PAYABLE', 'RECEIVABLE', 'INVESTMENT'], true)) {
            throw new \InvalidArgumentException('The category kind is invalid.');
        }

        $normalizedName = FinanceInput::normalizeNameKey($name);
        $this->assertCategoryUnique($ownerId, $normalizedName, $kind);

        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $this->connection->insert('finance_category', [
            'owner_id' => $ownerId,
            'name' => $name,
            'normalized_name' => $normalizedName,
            'kind' => $kind,
            'is_system' => FinanceInput::toDatabaseBoolean($isSystem),
            'is_active' => FinanceInput::toDatabaseBoolean(true),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $createdId = (int) $this->connection->lastInsertId();

        return $this->getCategoryById($ownerId, $createdId);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function updateCategory(User $user, int $categoryId, array $payload): array
    {
        $ownerId = $this->requireOwnerId($user);
        $existingCategory = $this->getCategoryById($ownerId, $categoryId);

        $name = array_key_exists('name', $payload)
            ? FinanceInput::normalizeName((string) $payload['name'])
            : (string) $existingCategory['name'];
        $kind = array_key_exists('kind', $payload)
            ? strtoupper(trim((string) $payload['kind']))
            : (string) $existingCategory['kind'];
        $isActive = array_key_exists('isActive', $payload)
            ? FinanceInput::normalizeBoolean($payload['isActive'], true)
            : (bool) $existingCategory['isActive'];

        if ($name === '') {
            throw new \InvalidArgumentException('The category name is required.');
        }

        if (!in_array($kind, ['BOTH', 'PAYABLE', 'RECEIVABLE', 'INVESTMENT'], true)) {
            throw new \InvalidArgumentException('The category kind is invalid.');
        }

        $normalizedName = FinanceInput::normalizeNameKey($name);
        $this->assertCategoryUnique($ownerId, $normalizedName, $kind, $categoryId);

        $this->connection->update('finance_category', [
            'name' => $name,
            'normalized_name' => $normalizedName,
            'kind' => $kind,
            'is_active' => FinanceInput::toDatabaseBoolean($isActive),
            'updated_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ], [
            'id' => $categoryId,
            'owner_id' => $ownerId,
        ]);

        return $this->getCategoryById($ownerId, $categoryId);
    }

    /**
     * @return array{items: list<array<string, mixed>>}
     */
    public function listRecurringTypes(User $user): array
    {
        $ownerId = $this->requireOwnerId($user);

        /** @var list<array<string, mixed>> $items */
        $items = $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT
                id,
                name,
                normalized_name AS "normalizedName",
                description,
                is_system AS "isSystem",
                is_active AS "isActive",
                created_at AS "createdAt",
                updated_at AS "updatedAt"
            FROM finance_recurring_type
            WHERE owner_id = :ownerId
            ORDER BY is_system DESC, name ASC
        SQL, ['ownerId' => $ownerId]);

        return ['items' => $items];
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function createRecurringType(User $user, array $payload): array
    {
        $ownerId = $this->requireOwnerId($user);
        $name = FinanceInput::normalizeName((string) ($payload['name'] ?? ''));
        $description = trim((string) ($payload['description'] ?? ''));
        $isSystem = FinanceInput::normalizeBoolean($payload['isSystem'] ?? false, false);

        if ($name === '') {
            throw new \InvalidArgumentException('The recurring type name is required.');
        }

        $normalizedName = FinanceInput::normalizeNameKey($name);
        $this->assertRecurringTypeUnique($ownerId, $normalizedName);

        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $this->connection->insert('finance_recurring_type', [
            'owner_id' => $ownerId,
            'name' => $name,
            'normalized_name' => $normalizedName,
            'description' => $description !== '' ? $description : null,
            'is_system' => FinanceInput::toDatabaseBoolean($isSystem),
            'is_active' => FinanceInput::toDatabaseBoolean(true),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $createdId = (int) $this->connection->lastInsertId();

        return $this->getRecurringTypeById($ownerId, $createdId);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function updateRecurringType(User $user, int $recurringTypeId, array $payload): array
    {
        $ownerId = $this->requireOwnerId($user);
        $existingRecurringType = $this->getRecurringTypeById($ownerId, $recurringTypeId);

        $name = array_key_exists('name', $payload)
            ? FinanceInput::normalizeName((string) $payload['name'])
            : (string) $existingRecurringType['name'];
        $description = array_key_exists('description', $payload)
            ? trim((string) $payload['description'])
            : (string) ($existingRecurringType['description'] ?? '');
        $isActive = array_key_exists('isActive', $payload)
            ? FinanceInput::normalizeBoolean($payload['isActive'], true)
            : (bool) $existingRecurringType['isActive'];

        if ($name === '') {
            throw new \InvalidArgumentException('The recurring type name is required.');
        }

        $normalizedName = FinanceInput::normalizeNameKey($name);
        $this->assertRecurringTypeUnique($ownerId, $normalizedName, $recurringTypeId);

        $this->connection->update('finance_recurring_type', [
            'name' => $name,
            'normalized_name' => $normalizedName,
            'description' => $description !== '' ? $description : null,
            'is_active' => FinanceInput::toDatabaseBoolean($isActive),
            'updated_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ], [
            'id' => $recurringTypeId,
            'owner_id' => $ownerId,
        ]);

        return $this->getRecurringTypeById($ownerId, $recurringTypeId);
    }

    /**
     * @return array{items: list<array<string, mixed>>}
     */
    public function listBankAccounts(User $user): array
    {
        $ownerId = $this->requireOwnerId($user);

        /** @var list<array<string, mixed>> $items */
        $items = $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT
                id,
                name,
                bank_name AS "bankName",
                account_type AS "accountType",
                current_balance_brl AS "currentBalanceBrl",
                color_hex AS "colorHex",
                icon_key AS "iconKey",
                is_active AS "isActive",
                created_at AS "createdAt",
                updated_at AS "updatedAt"
            FROM finance_bank_account
            WHERE owner_id = :ownerId
            ORDER BY is_active DESC, name ASC
        SQL, ['ownerId' => $ownerId]);

        return ['items' => $items];
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function createBankAccount(User $user, array $payload): array
    {
        $ownerId = $this->requireOwnerId($user);
        $name = FinanceInput::normalizeName((string) ($payload['name'] ?? ''));
        $bankName = FinanceInput::normalizeName((string) ($payload['bankName'] ?? ''));
        $accountType = strtoupper(trim((string) ($payload['accountType'] ?? 'CHECKING')));
        $currentBalanceBrl = FinanceInput::normalizeMoney($payload['currentBalanceBrl'] ?? 0, 'currentBalanceBrl');
        $colorHex = trim((string) ($payload['colorHex'] ?? ''));
        $iconKey = trim((string) ($payload['iconKey'] ?? ''));

        if ($name === '' || $bankName === '') {
            throw new \InvalidArgumentException('The bank account name and bank name are required.');
        }

        if (!in_array($accountType, ['CHECKING', 'SAVINGS', 'CREDIT', 'INVESTMENT', 'CASH'], true)) {
            throw new \InvalidArgumentException('The bank account type is invalid.');
        }

        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $this->connection->insert('finance_bank_account', [
            'owner_id' => $ownerId,
            'name' => $name,
            'bank_name' => $bankName,
            'account_type' => $accountType,
            'current_balance_brl' => $currentBalanceBrl,
            'color_hex' => $colorHex !== '' ? $colorHex : null,
            'icon_key' => $iconKey !== '' ? $iconKey : null,
            'is_active' => FinanceInput::toDatabaseBoolean(true),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $createdId = (int) $this->connection->lastInsertId();

        return $this->getBankAccountById($ownerId, $createdId);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function updateBankAccount(User $user, int $bankAccountId, array $payload): array
    {
        $ownerId = $this->requireOwnerId($user);
        $existingAccount = $this->getBankAccountById($ownerId, $bankAccountId);

        $name = array_key_exists('name', $payload)
            ? FinanceInput::normalizeName((string) $payload['name'])
            : (string) $existingAccount['name'];
        $bankName = array_key_exists('bankName', $payload)
            ? FinanceInput::normalizeName((string) $payload['bankName'])
            : (string) $existingAccount['bankName'];
        $accountType = array_key_exists('accountType', $payload)
            ? strtoupper(trim((string) $payload['accountType']))
            : (string) $existingAccount['accountType'];
        $currentBalanceBrl = array_key_exists('currentBalanceBrl', $payload)
            ? FinanceInput::normalizeMoney($payload['currentBalanceBrl'], 'currentBalanceBrl')
            : (float) $existingAccount['currentBalanceBrl'];
        $colorHex = array_key_exists('colorHex', $payload)
            ? trim((string) $payload['colorHex'])
            : trim((string) ($existingAccount['colorHex'] ?? ''));
        $iconKey = array_key_exists('iconKey', $payload)
            ? trim((string) $payload['iconKey'])
            : trim((string) ($existingAccount['iconKey'] ?? ''));
        $isActive = array_key_exists('isActive', $payload)
            ? FinanceInput::normalizeBoolean($payload['isActive'], true)
            : (bool) $existingAccount['isActive'];

        if ($name === '' || $bankName === '') {
            throw new \InvalidArgumentException('The bank account name and bank name are required.');
        }

        if (!in_array($accountType, ['CHECKING', 'SAVINGS', 'CREDIT', 'INVESTMENT', 'CASH'], true)) {
            throw new \InvalidArgumentException('The bank account type is invalid.');
        }

        $this->connection->update('finance_bank_account', [
            'name' => $name,
            'bank_name' => $bankName,
            'account_type' => $accountType,
            'current_balance_brl' => $currentBalanceBrl,
            'color_hex' => $colorHex !== '' ? $colorHex : null,
            'icon_key' => $iconKey !== '' ? $iconKey : null,
            'is_active' => FinanceInput::toDatabaseBoolean($isActive),
            'updated_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ], [
            'id' => $bankAccountId,
            'owner_id' => $ownerId,
        ]);

        return $this->getBankAccountById($ownerId, $bankAccountId);
    }

    /**
     * @return array<string, mixed>
     */
    public function updateBankAccountStatus(User $user, int $bankAccountId, bool $isActive): array
    {
        $ownerId = $this->requireOwnerId($user);
        $this->getBankAccountById($ownerId, $bankAccountId);

        $this->connection->update('finance_bank_account', [
            'is_active' => FinanceInput::toDatabaseBoolean($isActive),
            'updated_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ], [
            'id' => $bankAccountId,
            'owner_id' => $ownerId,
        ]);

        return $this->getBankAccountById($ownerId, $bankAccountId);
    }

    /**
     * @return array<string, mixed>
     */
    public function getBankAccountById(int $ownerId, int $bankAccountId): array
    {
        /** @var array<string, mixed>|false $result */
        $result = $this->connection->fetchAssociative(<<<'SQL'
            SELECT
                id,
                name,
                bank_name AS "bankName",
                account_type AS "accountType",
                current_balance_brl AS "currentBalanceBrl",
                color_hex AS "colorHex",
                icon_key AS "iconKey",
                is_active AS "isActive",
                created_at AS "createdAt",
                updated_at AS "updatedAt"
            FROM finance_bank_account
            WHERE owner_id = :ownerId
              AND id = :bankAccountId
            LIMIT 1
        SQL, [
            'ownerId' => $ownerId,
            'bankAccountId' => $bankAccountId,
        ]);

        if (!is_array($result)) {
            throw new \InvalidArgumentException('Bank account not found.');
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function getCategoryById(int $ownerId, int $categoryId): array
    {
        /** @var array<string, mixed>|false $result */
        $result = $this->connection->fetchAssociative(<<<'SQL'
            SELECT
                id,
                name,
                normalized_name AS "normalizedName",
                kind,
                is_system AS "isSystem",
                is_active AS "isActive",
                created_at AS "createdAt",
                updated_at AS "updatedAt"
            FROM finance_category
            WHERE owner_id = :ownerId
              AND id = :categoryId
            LIMIT 1
        SQL, [
            'ownerId' => $ownerId,
            'categoryId' => $categoryId,
        ]);

        if (!is_array($result)) {
            throw new \InvalidArgumentException('Category not found.');
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function getRecurringTypeById(int $ownerId, int $recurringTypeId): array
    {
        /** @var array<string, mixed>|false $result */
        $result = $this->connection->fetchAssociative(<<<'SQL'
            SELECT
                id,
                name,
                normalized_name AS "normalizedName",
                description,
                is_system AS "isSystem",
                is_active AS "isActive",
                created_at AS "createdAt",
                updated_at AS "updatedAt"
            FROM finance_recurring_type
            WHERE owner_id = :ownerId
              AND id = :recurringTypeId
            LIMIT 1
        SQL, [
            'ownerId' => $ownerId,
            'recurringTypeId' => $recurringTypeId,
        ]);

        if (!is_array($result)) {
            throw new \InvalidArgumentException('Recurring type not found.');
        }

        return $result;
    }

    private function assertCategoryUnique(int $ownerId, string $normalizedName, string $kind, ?int $excludeCategoryId = null): void
    {
        $sql = <<<'SQL'
            SELECT id
            FROM finance_category
            WHERE owner_id = :ownerId
              AND normalized_name = :normalizedName
              AND kind = :kind
        SQL;

        $parameters = [
            'ownerId' => $ownerId,
            'normalizedName' => $normalizedName,
            'kind' => $kind,
        ];

        if ($excludeCategoryId !== null) {
            $sql .= ' AND id != :excludeCategoryId';
            $parameters['excludeCategoryId'] = $excludeCategoryId;
        }

        $alreadyExists = $this->connection->fetchOne($sql . ' LIMIT 1', $parameters);
        if ($alreadyExists !== false) {
            throw new \InvalidArgumentException('There is already a category with this name and kind.');
        }
    }

    private function assertRecurringTypeUnique(int $ownerId, string $normalizedName, ?int $excludeRecurringTypeId = null): void
    {
        $sql = <<<'SQL'
            SELECT id
            FROM finance_recurring_type
            WHERE owner_id = :ownerId
              AND normalized_name = :normalizedName
        SQL;

        $parameters = [
            'ownerId' => $ownerId,
            'normalizedName' => $normalizedName,
        ];

        if ($excludeRecurringTypeId !== null) {
            $sql .= ' AND id != :excludeRecurringTypeId';
            $parameters['excludeRecurringTypeId'] = $excludeRecurringTypeId;
        }

        $alreadyExists = $this->connection->fetchOne($sql . ' LIMIT 1', $parameters);
        if ($alreadyExists !== false) {
            throw new \InvalidArgumentException('There is already a recurring type with this name.');
        }
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
