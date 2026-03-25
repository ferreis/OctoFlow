<?php

namespace App\Finance;

use App\Entity\User;
use Doctrine\DBAL\Connection;

final class FinanceOpenFinanceService
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * @return array{items: list<array<string, mixed>>}
     */
    public function listProviders(): array
    {
        /** @var list<array<string, mixed>> $items */
        $items = $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT
                id,
                provider_code AS "providerCode",
                name,
                is_active AS "isActive"
            FROM finance_external_provider
            ORDER BY is_active DESC, name ASC
        SQL);

        return [
            'items' => $items,
        ];
    }

    /**
     * @return array{items: list<array<string, mixed>>}
     */
    public function listConnections(User $user): array
    {
        $ownerId = $this->requireOwnerId($user);

        /** @var list<array<string, mixed>> $items */
        $items = $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT
                connection.id,
                connection.status,
                connection.external_consent_id AS "externalConsentId",
                connection.consent_expires_at AS "consentExpiresAt",
                connection.last_sync_at AS "lastSyncAt",
                connection.created_at AS "createdAt",
                provider.id AS "providerId",
                provider.provider_code AS "providerCode",
                provider.name AS "providerName",
                (
                    SELECT COUNT(*)
                    FROM finance_external_account account
                    WHERE account.connection_id = connection.id
                ) AS "accountsCount",
                (
                    SELECT COUNT(*)
                    FROM finance_external_transaction transaction
                    INNER JOIN finance_external_account account ON account.id = transaction.external_account_id
                    WHERE account.connection_id = connection.id
                ) AS "transactionsCount"
            FROM finance_external_connection connection
            INNER JOIN finance_external_provider provider ON provider.id = connection.provider_id
            WHERE connection.owner_id = :ownerId
            ORDER BY connection.created_at DESC, connection.id DESC
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
    public function createConnection(User $user, array $payload): array
    {
        $ownerId = $this->requireOwnerId($user);

        $providerId = $this->resolveProviderId($payload);

        $status = strtoupper(trim((string) ($payload['status'] ?? 'ACTIVE')));
        if (!in_array($status, ['ACTIVE', 'REVOKED', 'EXPIRED', 'ERROR'], true)) {
            throw new \InvalidArgumentException('The informed Open Finance connection status is invalid.');
        }

        $consentExpiresAt = FinanceInput::normalizeOptionalDate($payload['consentExpiresAt'] ?? null);
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $this->connection->insert('finance_external_connection', [
            'owner_id' => $ownerId,
            'provider_id' => $providerId,
            'external_consent_id' => trim((string) ($payload['externalConsentId'] ?? '')) ?: null,
            'status' => $status,
            'consent_expires_at' => $consentExpiresAt?->format('Y-m-d H:i:s'),
            'last_sync_at' => null,
            'created_at' => $now,
        ]);

        $connectionId = (int) $this->connection->lastInsertId();

        return $this->getConnectionById($ownerId, $connectionId);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function syncConnection(User $user, int $connectionId, array $payload = []): array
    {
        $ownerId = $this->requireOwnerId($user);
        $connection = $this->getConnectionById($ownerId, $connectionId);

        $syncTimestamp = new \DateTimeImmutable();

        $this->connection->update('finance_external_connection', [
            'status' => 'ACTIVE',
            'last_sync_at' => $syncTimestamp->format('Y-m-d H:i:s'),
        ], [
            'id' => $connectionId,
            'owner_id' => $ownerId,
        ]);

        $importedAccountsCount = 0;
        $importedTransactionsCount = 0;

        $createMockData = FinanceInput::normalizeBoolean($payload['createMockData'] ?? false, false);
        if ($createMockData && (string) $connection['providerCode'] === 'MANUAL_MOCK') {
            [$importedAccountsCount, $importedTransactionsCount] = $this->createMockData($connectionId);
        }

        return [
            'connection' => $this->getConnectionById($ownerId, $connectionId),
            'sync' => [
                'syncedAt' => $syncTimestamp->format('Y-m-d H:i:s'),
                'importedAccountsCount' => $importedAccountsCount,
                'importedTransactionsCount' => $importedTransactionsCount,
                'mode' => $createMockData ? 'mock' : 'stub',
            ],
        ];
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function createMockData(int $connectionId): array
    {
        $createdAccountsCount = 0;
        $createdTransactionsCount = 0;

        $existingAccountId = $this->connection->fetchOne(<<<'SQL'
            SELECT id
            FROM finance_external_account
            WHERE connection_id = :connectionId
              AND external_account_id = :externalAccountId
            LIMIT 1
        SQL, [
            'connectionId' => $connectionId,
            'externalAccountId' => 'MOCK_ACCOUNT_MAIN',
        ]);

        if ($existingAccountId === false) {
            $this->connection->insert('finance_external_account', [
                'connection_id' => $connectionId,
                'external_account_id' => 'MOCK_ACCOUNT_MAIN',
                'display_name' => 'Conta Corrente Mock',
                'bank_name' => 'Banco Mock',
                'account_type' => 'CHECKING',
                'currency_code' => 'BRL',
                'is_active' => FinanceInput::toDatabaseBoolean(true),
            ]);

            $existingAccountId = (int) $this->connection->lastInsertId();
            $createdAccountsCount += 1;
        }

        $mockTransactionId = sprintf('MOCK_TX_%s', (new \DateTimeImmutable())->format('YmdHis'));

        $this->connection->insert('finance_external_transaction', [
            'external_account_id' => (int) $existingAccountId,
            'external_transaction_id' => $mockTransactionId,
            'posted_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            'amount' => 123.45,
            'currency_code' => 'BRL',
            'direction' => 'RECEIVABLE',
            'description' => 'Mock transaction generated for Open Finance scaffolding.',
            'raw_payload' => json_encode([
                'source' => 'MANUAL_MOCK',
                'message' => 'Mock transaction generated by sync endpoint.',
            ], \JSON_THROW_ON_ERROR),
            'normalized_category' => 'mock',
        ]);

        $createdTransactionsCount += 1;

        return [$createdAccountsCount, $createdTransactionsCount];
    }

    private function resolveProviderId(array $payload): int
    {
        $providerId = (int) ($payload['providerId'] ?? 0);
        if ($providerId > 0) {
            $exists = $this->connection->fetchOne(
                'SELECT id FROM finance_external_provider WHERE id = :providerId AND is_active = true LIMIT 1',
                ['providerId' => $providerId],
            );

            if ($exists === false) {
                throw new \InvalidArgumentException('Open Finance provider not found or inactive.');
            }

            return $providerId;
        }

        $providerCode = strtoupper(trim((string) ($payload['providerCode'] ?? '')));
        if ($providerCode === '') {
            throw new \InvalidArgumentException('providerId or providerCode is required to create a connection.');
        }

        $foundProviderId = $this->connection->fetchOne(
            'SELECT id FROM finance_external_provider WHERE provider_code = :providerCode AND is_active = true LIMIT 1',
            ['providerCode' => $providerCode],
        );

        if ($foundProviderId === false) {
            throw new \InvalidArgumentException('Open Finance provider not found or inactive.');
        }

        return (int) $foundProviderId;
    }

    /**
     * @return array<string, mixed>
     */
    private function getConnectionById(int $ownerId, int $connectionId): array
    {
        /** @var array<string, mixed>|false $connection */
        $connection = $this->connection->fetchAssociative(<<<'SQL'
            SELECT
                connection.id,
                connection.status,
                connection.external_consent_id AS "externalConsentId",
                connection.consent_expires_at AS "consentExpiresAt",
                connection.last_sync_at AS "lastSyncAt",
                connection.created_at AS "createdAt",
                provider.id AS "providerId",
                provider.provider_code AS "providerCode",
                provider.name AS "providerName"
            FROM finance_external_connection connection
            INNER JOIN finance_external_provider provider ON provider.id = connection.provider_id
            WHERE connection.owner_id = :ownerId
              AND connection.id = :connectionId
            LIMIT 1
        SQL, [
            'ownerId' => $ownerId,
            'connectionId' => $connectionId,
        ]);

        if (!is_array($connection)) {
            throw new \InvalidArgumentException('Open Finance connection not found.');
        }

        return $connection;
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
