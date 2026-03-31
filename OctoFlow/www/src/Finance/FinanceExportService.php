<?php

namespace App\Finance;

use App\Entity\User;
use Doctrine\DBAL\Connection;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class FinanceExportService
{
    public function __construct(
        private readonly Connection $connection,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function createJob(User $user, array $payload): array
    {
        $ownerId = $this->requireOwnerId($user);

        $exportType = strtoupper(trim((string) ($payload['exportType'] ?? '')));
        if (!in_array($exportType, FinanceConstants::EXPORT_TYPES, true)) {
            throw new \InvalidArgumentException('The informed export type is invalid.');
        }

        $filters = is_array($payload['filters'] ?? null) ? $payload['filters'] : [];
        $now = new \DateTimeImmutable();

        $this->connection->insert('finance_export_job', [
            'owner_id' => $ownerId,
            'export_type' => $exportType,
            'filters_json' => json_encode($filters, \JSON_THROW_ON_ERROR),
            'status' => 'QUEUED',
            'requested_at' => $now->format('Y-m-d H:i:s'),
            'expires_at' => $now->modify('+7 days')->format('Y-m-d H:i:s'),
        ]);

        $jobId = (int) $this->connection->lastInsertId();

        return $this->getJobById($ownerId, $jobId);
    }

    /**
     * @param array<string, mixed> $filters
     *
     * @return array{items: list<array<string, mixed>>, meta: array<string, int>}
     */
    public function listJobs(User $user, array $filters = []): array
    {
        $ownerId = $this->requireOwnerId($user);
        $pagination = FinanceInput::normalizePagination($filters['page'] ?? 1, $filters['itemsPerPage'] ?? 10);

        $whereParts = ['owner_id = :ownerId'];
        $parameters = ['ownerId' => $ownerId];

        $status = strtoupper(trim((string) ($filters['status'] ?? '')));
        if ($status !== '') {
            $whereParts[] = 'status = :status';
            $parameters['status'] = $status;
        }

        $whereSql = implode(' AND ', $whereParts);

        $total = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM finance_export_job WHERE ' . $whereSql,
            $parameters,
        );

        $parameters['limit'] = $pagination['itemsPerPage'];
        $parameters['offset'] = $pagination['offset'];

        /** @var list<array<string, mixed>> $items */
        $items = $this->connection->fetchAllAssociative(<<<SQL
            SELECT
                id,
                export_type AS "exportType",
                filters_json AS "filtersJson",
                status,
                file_name AS "fileName",
                file_path AS "filePath",
                requested_at AS "requestedAt",
                started_at AS "startedAt",
                finished_at AS "finishedAt",
                expires_at AS "expiresAt",
                error_message AS "errorMessage"
            FROM finance_export_job
            WHERE {$whereSql}
            ORDER BY requested_at DESC, id DESC
            LIMIT :limit OFFSET :offset
        SQL, $parameters);

        return [
            'items' => array_map(fn (array $item): array => $this->normalizeJobPayload($item), $items),
            'meta' => [
                'page' => $pagination['page'],
                'itemsPerPage' => $pagination['itemsPerPage'],
                'total' => $total,
            ],
        ];
    }

    /**
     * @return array{id: int, status: string}
     */
    public function deleteJob(User $user, int $jobId): array
    {
        $ownerId = $this->requireOwnerId($user);
        $job = $this->getJobById($ownerId, $jobId);

        $deletedRows = $this->connection->executeStatement(<<<'SQL'
            DELETE FROM finance_export_job
            WHERE owner_id = :ownerId
              AND id = :jobId
              AND status <> 'PROCESSING'
        SQL, [
            'ownerId' => $ownerId,
            'jobId' => $jobId,
        ]);

        if ($deletedRows <= 0) {
            throw new \InvalidArgumentException('Export job cannot be deleted while processing.');
        }

        $filePath = trim((string) ($job['filePath'] ?? ''));
        if ($filePath !== '' && is_file($filePath)) {
            @unlink($filePath);
        }

        return [
            'id' => $jobId,
            'status' => 'DELETED',
        ];
    }

    /**
     * @return array{processed: int, failed: int}
     */
    public function processQueuedJobs(int $limit = 10): array
    {
        $normalizedLimit = max(1, min(100, $limit));

        /** @var list<array<string, mixed>> $jobRows */
        $jobRows = $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT id
            FROM finance_export_job
            WHERE status = 'QUEUED'
            ORDER BY requested_at ASC, id ASC
            LIMIT :limit
        SQL, [
            'limit' => $normalizedLimit,
        ]);

        $processedCount = 0;
        $failedCount = 0;

        foreach ($jobRows as $jobRow) {
            $jobId = (int) $jobRow['id'];
            $startedAt = new \DateTimeImmutable();

            $claimedRows = $this->connection->executeStatement(<<<'SQL'
                UPDATE finance_export_job
                SET status = 'PROCESSING', started_at = :startedAt, error_message = NULL
                WHERE id = :jobId
                  AND status = 'QUEUED'
            SQL, [
                'startedAt' => $startedAt->format('Y-m-d H:i:s'),
                'jobId' => $jobId,
            ]);

            if ($claimedRows <= 0) {
                continue;
            }

            try {
                /** @var array<string, mixed>|false $job */
                $job = $this->connection->fetchAssociative(<<<'SQL'
                    SELECT id, owner_id, export_type, filters_json
                    FROM finance_export_job
                    WHERE id = :jobId
                    LIMIT 1
                SQL, [
                    'jobId' => $jobId,
                ]);

                if (!is_array($job)) {
                    throw new \RuntimeException('Export job not found right after claiming it.');
                }

                $ownerId = (int) $job['owner_id'];
                $filters = $this->decodeFilters((string) ($job['filters_json'] ?? '{}'));
                $exportType = (string) $job['export_type'];

                $builtFile = $this->generateExportFile($jobId, $ownerId, $exportType, $filters);
                $finishedAt = new \DateTimeImmutable();

                $this->connection->update('finance_export_job', [
                    'status' => 'DONE',
                    'file_name' => $builtFile['fileName'],
                    'file_path' => $builtFile['filePath'],
                    'finished_at' => $finishedAt->format('Y-m-d H:i:s'),
                    'expires_at' => $finishedAt->modify('+7 days')->format('Y-m-d H:i:s'),
                ], [
                    'id' => $jobId,
                ]);

                ++$processedCount;
            } catch (\Throwable $throwable) {
                $this->connection->update('finance_export_job', [
                    'status' => 'FAILED',
                    'finished_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                    'error_message' => mb_substr($throwable->getMessage(), 0, 4000),
                ], [
                    'id' => $jobId,
                ]);

                ++$failedCount;
            }
        }

        return [
            'processed' => $processedCount,
            'failed' => $failedCount,
        ];
    }

    public function getDownloadableFilePath(User $user, int $jobId): string
    {
        $ownerId = $this->requireOwnerId($user);
        $job = $this->getJobById($ownerId, $jobId);

        if ((string) $job['status'] !== 'DONE') {
            throw new \InvalidArgumentException('Export job is not finished yet.');
        }

        $expiresAt = FinanceInput::normalizeOptionalDate($job['expiresAt'] ?? null);
        if ($expiresAt instanceof \DateTimeImmutable && $expiresAt < new \DateTimeImmutable('now')) {
            throw new \InvalidArgumentException('Export file expired. Request a new export.');
        }

        $filePath = trim((string) ($job['filePath'] ?? ''));
        if ($filePath === '' || !is_file($filePath)) {
            throw new \InvalidArgumentException('Export file not found.');
        }

        return $filePath;
    }

    /**
     * @param array<string, mixed> $filters
     *
     * @return array{fileName: string, filePath: string}
     */
    private function generateExportFile(int $jobId, int $ownerId, string $exportType, array $filters): array
    {
        if (!class_exists(Spreadsheet::class) || !class_exists(Xlsx::class)) {
            throw new \RuntimeException('phpoffice/phpspreadsheet is not installed.');
        }

        $timestampToken = (new \DateTimeImmutable())->format('Ymd_His');
        $safeType = mb_strtolower($exportType);
        $fileName = sprintf('finance_%s_%d_%s.xlsx', $safeType, $jobId, $timestampToken);

        $directoryPath = $this->resolveExportDirectory($ownerId);

        $filePath = sprintf('%s/%s', $directoryPath, $fileName);

        $sheets = $this->buildSheets($ownerId, $exportType, $filters);
        $this->writeSpreadsheet($sheets, $filePath);

        return [
            'fileName' => $fileName,
            'filePath' => $filePath,
        ];
    }

    private function resolveExportDirectory(int $ownerId): string
    {
        $preferredDirectory = sprintf('%s/var/finance_exports/%d', $this->projectDir, $ownerId);
        if ($this->ensureWritableDirectory($preferredDirectory)) {
            return $preferredDirectory;
        }

        $temporaryDirectory = sprintf('%s/octoflow_finance_exports/%d', rtrim(sys_get_temp_dir(), '/'), $ownerId);
        if ($this->ensureWritableDirectory($temporaryDirectory)) {
            return $temporaryDirectory;
        }

        throw new \RuntimeException('Failed to create a writable export directory.');
    }

    private function ensureWritableDirectory(string $directoryPath): bool
    {
        if (!is_dir($directoryPath) && !@mkdir($directoryPath, 0775, true) && !is_dir($directoryPath)) {
            return false;
        }

        return is_writable($directoryPath);
    }

    /**
     * @param array<string, mixed> $filters
     *
     * @return list<array{name: string, headers: list<string>, rows: list<array<int, scalar|null>>}>
     */
    private function buildSheets(int $ownerId, string $exportType, array $filters): array
    {
        $metadataRows = [
            ['Owner ID', $ownerId],
            ['Export Type', $exportType],
            ['Generated At (UTC)', (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s')],
            ['Filters', json_encode($filters, \JSON_UNESCAPED_UNICODE)],
        ];

        $sheets = [
            [
                'name' => 'Metadata',
                'headers' => ['Field', 'Value'],
                'rows' => $metadataRows,
            ],
        ];

        return [...$sheets, ...$this->buildDataSheets($ownerId, $exportType, $filters)];
    }

    /**
     * @param array<string, mixed> $filters
     *
     * @return list<array{name: string, headers: list<string>, rows: list<array<int, scalar|null>>}>
     */
    private function buildDataSheets(int $ownerId, string $exportType, array $filters): array
    {
        return match ($exportType) {
            'PAYABLE' => [$this->buildEntrySheet($ownerId, $filters, 'PAYABLE', 'ContasPagar')],
            'RECEIVABLE' => [$this->buildEntrySheet($ownerId, $filters, 'RECEIVABLE', 'ContasReceber')],
            'MONTHLY_SUMMARY' => [$this->buildMonthlySummarySheet($ownerId, $filters)],
            'CATEGORY' => [$this->buildCategorySheet($ownerId, $filters)],
            'CASHFLOW' => [$this->buildCashflowSheet($ownerId, $filters)],
            'INVESTMENT' => [
                $this->buildInvestmentPlansSheet($ownerId),
                $this->buildInvestmentRunsSheet($ownerId),
                $this->buildInvestmentSimulationsSheet($ownerId),
            ],
            default => throw new \InvalidArgumentException('Unsupported export type.'),
        };
    }

    /**
     * @param array<string, mixed> $filters
     *
     * @return array{name: string, headers: list<string>, rows: list<array<int, scalar|null>>}
     */
    private function buildEntrySheet(int $ownerId, array $filters, string $direction, string $sheetName): array
    {
        [$whereSql, $parameters] = $this->buildEntryWhere($ownerId, ['direction' => $direction, ...$filters]);

        /** @var list<array<string, mixed>> $rows */
        $rows = $this->connection->fetchAllAssociative(<<<SQL
            SELECT
                entry.id,
                entry.direction,
                entry.entry_type,
                entry.status,
                entry.title,
                entry.description,
                entry.due_date,
                entry.competence_month,
                entry.expected_amount_brl,
                entry.settled_amount_brl,
                entry.remaining_amount_brl,
                entry.source_origin,
                entry.source_system,
                category.name AS category_name,
                bank_account.name AS bank_account_name
            FROM finance_entry entry
            LEFT JOIN finance_category category ON category.id = entry.category_id
            LEFT JOIN finance_bank_account bank_account ON bank_account.id = entry.bank_account_id
            WHERE {$whereSql}
            ORDER BY entry.due_date ASC NULLS LAST, entry.id DESC
        SQL, $parameters);

        return [
            'name' => $sheetName,
            'headers' => [
                'ID',
                'Direction',
                'Type',
                'Status',
                'Title',
                'Description',
                'Due Date',
                'Competence Month',
                'Expected BRL',
                'Settled BRL',
                'Remaining BRL',
                'Source Origin',
                'Source System',
                'Category',
                'Bank Account',
            ],
            'rows' => array_map(static function (array $row): array {
                return [
                    (int) $row['id'],
                    (string) $row['direction'],
                    (string) $row['entry_type'],
                    (string) $row['status'],
                    (string) $row['title'],
                    $row['description'] !== null ? (string) $row['description'] : null,
                    $row['due_date'] !== null ? (string) $row['due_date'] : null,
                    $row['competence_month'] !== null ? (string) $row['competence_month'] : null,
                    round((float) $row['expected_amount_brl'], 2),
                    round((float) $row['settled_amount_brl'], 2),
                    round((float) $row['remaining_amount_brl'], 2),
                    (string) $row['source_origin'],
                    $row['source_system'] !== null ? (string) $row['source_system'] : null,
                    $row['category_name'] !== null ? (string) $row['category_name'] : null,
                    $row['bank_account_name'] !== null ? (string) $row['bank_account_name'] : null,
                ];
            }, $rows),
        ];
    }

    /**
     * @param array<string, mixed> $filters
     *
     * @return array{name: string, headers: list<string>, rows: list<array<int, scalar|null>>}
     */
    private function buildMonthlySummarySheet(int $ownerId, array $filters): array
    {
        [$whereSql, $parameters] = $this->buildEntryWhere($ownerId, $filters);

        /** @var list<array<string, mixed>> $rows */
        $rows = $this->connection->fetchAllAssociative(<<<SQL
            SELECT
                TO_CHAR(COALESCE(entry.competence_month, entry.due_date), 'YYYY-MM-01') AS competence_month,
                COALESCE(SUM(CASE WHEN entry.direction = 'RECEIVABLE' THEN entry.expected_amount_brl ELSE 0 END), 0) AS expected_income_brl,
                COALESCE(SUM(CASE WHEN entry.direction = 'PAYABLE' THEN entry.expected_amount_brl ELSE 0 END), 0) AS expected_expense_brl,
                COALESCE(SUM(CASE WHEN entry.direction = 'RECEIVABLE' THEN entry.settled_amount_brl ELSE 0 END), 0) AS realized_income_brl,
                COALESCE(SUM(CASE WHEN entry.direction = 'PAYABLE' THEN entry.settled_amount_brl ELSE 0 END), 0) AS realized_expense_brl,
                COUNT(entry.id) AS entries_count
            FROM finance_entry entry
            WHERE {$whereSql}
              AND COALESCE(entry.competence_month, entry.due_date) IS NOT NULL
            GROUP BY TO_CHAR(COALESCE(entry.competence_month, entry.due_date), 'YYYY-MM-01')
            ORDER BY competence_month ASC
        SQL, $parameters);

        return [
            'name' => 'ResumoMensal',
            'headers' => ['Competence Month', 'Expected Income BRL', 'Expected Expense BRL', 'Expected Net BRL', 'Realized Income BRL', 'Realized Expense BRL', 'Realized Net BRL', 'Entries Count'],
            'rows' => array_map(static function (array $row): array {
                $expectedIncomeBrl = (float) $row['expected_income_brl'];
                $expectedExpenseBrl = (float) $row['expected_expense_brl'];
                $realizedIncomeBrl = (float) $row['realized_income_brl'];
                $realizedExpenseBrl = (float) $row['realized_expense_brl'];

                return [
                    (string) $row['competence_month'],
                    round($expectedIncomeBrl, 2),
                    round($expectedExpenseBrl, 2),
                    round($expectedIncomeBrl - $expectedExpenseBrl, 2),
                    round($realizedIncomeBrl, 2),
                    round($realizedExpenseBrl, 2),
                    round($realizedIncomeBrl - $realizedExpenseBrl, 2),
                    (int) $row['entries_count'],
                ];
            }, $rows),
        ];
    }

    /**
     * @param array<string, mixed> $filters
     *
     * @return array{name: string, headers: list<string>, rows: list<array<int, scalar|null>>}
     */
    private function buildCategorySheet(int $ownerId, array $filters): array
    {
        [$whereSql, $parameters] = $this->buildEntryWhere($ownerId, $filters);

        /** @var list<array<string, mixed>> $rows */
        $rows = $this->connection->fetchAllAssociative(<<<SQL
            SELECT
                COALESCE(category.name, 'Sem categoria') AS category_name,
                COALESCE(SUM(CASE WHEN entry.direction = 'RECEIVABLE' THEN entry.expected_amount_brl ELSE 0 END), 0) AS expected_income_brl,
                COALESCE(SUM(CASE WHEN entry.direction = 'PAYABLE' THEN entry.expected_amount_brl ELSE 0 END), 0) AS expected_expense_brl,
                COALESCE(SUM(CASE WHEN entry.direction = 'RECEIVABLE' THEN entry.settled_amount_brl ELSE 0 END), 0) AS realized_income_brl,
                COALESCE(SUM(CASE WHEN entry.direction = 'PAYABLE' THEN entry.settled_amount_brl ELSE 0 END), 0) AS realized_expense_brl,
                COUNT(entry.id) AS entries_count
            FROM finance_entry entry
            LEFT JOIN finance_category category ON category.id = entry.category_id
            WHERE {$whereSql}
            GROUP BY COALESCE(category.name, 'Sem categoria')
            ORDER BY COALESCE(SUM(entry.expected_amount_brl), 0) DESC
        SQL, $parameters);

        return [
            'name' => 'Categorias',
            'headers' => ['Category', 'Expected Income BRL', 'Expected Expense BRL', 'Expected Net BRL', 'Realized Income BRL', 'Realized Expense BRL', 'Realized Net BRL', 'Entries Count'],
            'rows' => array_map(static function (array $row): array {
                $expectedIncomeBrl = (float) $row['expected_income_brl'];
                $expectedExpenseBrl = (float) $row['expected_expense_brl'];
                $realizedIncomeBrl = (float) $row['realized_income_brl'];
                $realizedExpenseBrl = (float) $row['realized_expense_brl'];

                return [
                    (string) $row['category_name'],
                    round($expectedIncomeBrl, 2),
                    round($expectedExpenseBrl, 2),
                    round($expectedIncomeBrl - $expectedExpenseBrl, 2),
                    round($realizedIncomeBrl, 2),
                    round($realizedExpenseBrl, 2),
                    round($realizedIncomeBrl - $realizedExpenseBrl, 2),
                    (int) $row['entries_count'],
                ];
            }, $rows),
        ];
    }

    /**
     * @param array<string, mixed> $filters
     *
     * @return array{name: string, headers: list<string>, rows: list<array<int, scalar|null>>}
     */
    private function buildCashflowSheet(int $ownerId, array $filters): array
    {
        [$whereSql, $parameters] = $this->buildEntryWhere($ownerId, $filters);

        /** @var list<array<string, mixed>> $rows */
        $rows = $this->connection->fetchAllAssociative(<<<SQL
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

        return [
            'name' => 'FluxoFinanceiro',
            'headers' => ['Competence Month', 'Expected In BRL', 'Expected Out BRL', 'Expected Net BRL', 'Realized In BRL', 'Realized Out BRL', 'Realized Net BRL'],
            'rows' => array_map(static function (array $row): array {
                $expectedIncomeBrl = (float) $row['expected_income_brl'];
                $expectedExpenseBrl = (float) $row['expected_expense_brl'];
                $realizedIncomeBrl = (float) $row['realized_income_brl'];
                $realizedExpenseBrl = (float) $row['realized_expense_brl'];

                return [
                    (string) $row['competence_month'],
                    round($expectedIncomeBrl, 2),
                    round($expectedExpenseBrl, 2),
                    round($expectedIncomeBrl - $expectedExpenseBrl, 2),
                    round($realizedIncomeBrl, 2),
                    round($realizedExpenseBrl, 2),
                    round($realizedIncomeBrl - $realizedExpenseBrl, 2),
                ];
            }, $rows),
        ];
    }

    /**
     * @return array{name: string, headers: list<string>, rows: list<array<int, scalar|null>>}
     */
    private function buildInvestmentPlansSheet(int $ownerId): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT
                id,
                label,
                investment_type,
                start_date,
                contribution_day,
                monthly_contribution_brl,
                effective_monthly_rate,
                generate_yield_entries,
                yield_mode,
                status,
                created_at,
                updated_at
            FROM finance_investment_plan
            WHERE owner_id = :ownerId
            ORDER BY created_at DESC
        SQL, [
            'ownerId' => $ownerId,
        ]);

        return [
            'name' => 'InvestPlanos',
            'headers' => ['ID', 'Label', 'Type', 'Start Date', 'Contribution Day', 'Monthly Contribution BRL', 'Effective Monthly Rate', 'Generate Yield Entries', 'Yield Mode', 'Status', 'Created At', 'Updated At'],
            'rows' => array_map(static function (array $row): array {
                return [
                    (int) $row['id'],
                    (string) $row['label'],
                    (string) $row['investment_type'],
                    (string) $row['start_date'],
                    (int) $row['contribution_day'],
                    round((float) $row['monthly_contribution_brl'], 2),
                    (float) $row['effective_monthly_rate'],
                    (bool) $row['generate_yield_entries'] ? 'true' : 'false',
                    (string) $row['yield_mode'],
                    (string) $row['status'],
                    (string) $row['created_at'],
                    (string) $row['updated_at'],
                ];
            }, $rows),
        ];
    }

    /**
     * @return array{name: string, headers: list<string>, rows: list<array<int, scalar|null>>}
     */
    private function buildInvestmentRunsSheet(int $ownerId): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT
                run.id,
                run.investment_plan_id,
                plan.label AS plan_label,
                run.competence_month,
                run.run_source,
                run.generated_at,
                contribution_entry.expected_amount_brl AS contribution_amount_brl,
                yield_entry.expected_amount_brl AS yield_amount_brl
            FROM finance_investment_plan_run run
            INNER JOIN finance_investment_plan plan ON plan.id = run.investment_plan_id
            LEFT JOIN finance_entry contribution_entry ON contribution_entry.id = run.contribution_entry_id
              AND contribution_entry.deleted_at IS NULL
            LEFT JOIN finance_entry yield_entry ON yield_entry.id = run.yield_entry_id
              AND yield_entry.deleted_at IS NULL
            WHERE plan.owner_id = :ownerId
            ORDER BY run.generated_at DESC
        SQL, [
            'ownerId' => $ownerId,
        ]);

        return [
            'name' => 'InvestExecucoes',
            'headers' => ['Run ID', 'Plan ID', 'Plan Label', 'Competence Month', 'Run Source', 'Generated At', 'Contribution Amount BRL', 'Yield Amount BRL'],
            'rows' => array_map(static function (array $row): array {
                return [
                    (int) $row['id'],
                    (int) $row['investment_plan_id'],
                    (string) $row['plan_label'],
                    (string) $row['competence_month'],
                    (string) $row['run_source'],
                    (string) $row['generated_at'],
                    $row['contribution_amount_brl'] !== null ? round((float) $row['contribution_amount_brl'], 2) : null,
                    $row['yield_amount_brl'] !== null ? round((float) $row['yield_amount_brl'], 2) : null,
                ];
            }, $rows),
        ];
    }

    /**
     * @return array{name: string, headers: list<string>, rows: list<array<int, scalar|null>>}
     */
    private function buildInvestmentSimulationsSheet(int $ownerId): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT
                id,
                investment_type,
                label,
                initial_amount_brl,
                monthly_contribution_brl,
                period_months,
                rate_input_type,
                rate_value,
                effective_monthly_rate,
                total_invested_brl,
                total_yield_brl,
                final_amount_brl,
                created_at
            FROM finance_investment_simulation
            WHERE owner_id = :ownerId
            ORDER BY created_at DESC
        SQL, [
            'ownerId' => $ownerId,
        ]);

        return [
            'name' => 'InvestSimulacoes',
            'headers' => ['Simulation ID', 'Type', 'Label', 'Initial BRL', 'Monthly BRL', 'Period Months', 'Rate Input Type', 'Rate Value', 'Effective Monthly Rate', 'Total Invested BRL', 'Total Yield BRL', 'Final Amount BRL', 'Created At'],
            'rows' => array_map(static function (array $row): array {
                return [
                    (int) $row['id'],
                    (string) $row['investment_type'],
                    (string) $row['label'],
                    round((float) $row['initial_amount_brl'], 2),
                    round((float) $row['monthly_contribution_brl'], 2),
                    (int) $row['period_months'],
                    (string) $row['rate_input_type'],
                    (float) $row['rate_value'],
                    (float) $row['effective_monthly_rate'],
                    round((float) $row['total_invested_brl'], 2),
                    round((float) $row['total_yield_brl'], 2),
                    round((float) $row['final_amount_brl'], 2),
                    (string) $row['created_at'],
                ];
            }, $rows),
        ];
    }

    /**
     * @param list<array{name: string, headers: list<string>, rows: list<array<int, scalar|null>>}> $sheets
     */
    private function writeSpreadsheet(array $sheets, string $filePath): void
    {
        $spreadsheet = new Spreadsheet();

        $defaultSheet = $spreadsheet->getActiveSheet();
        $spreadsheet->removeSheetByIndex($spreadsheet->getIndex($defaultSheet));

        foreach ($sheets as $sheetDefinition) {
            $sheetName = $this->sanitizeSheetName($sheetDefinition['name']);
            $worksheet = $spreadsheet->createSheet();
            $worksheet->setTitle($sheetName);

            $headers = $sheetDefinition['headers'];
            $rows = $sheetDefinition['rows'];

            if ($headers !== []) {
                $worksheet->fromArray($headers, null, 'A1');
                $worksheet->freezePane('A2');
            }

            if ($rows !== []) {
                $worksheet->fromArray($rows, null, 'A2');
            }

            if ($headers !== []) {
                $lastColumn = Coordinate::stringFromColumnIndex(count($headers));
                $worksheet->setAutoFilter(sprintf('A1:%s1', $lastColumn));

                for ($columnIndex = 1; $columnIndex <= count($headers); $columnIndex += 1) {
                    $worksheet->getColumnDimension(Coordinate::stringFromColumnIndex($columnIndex))->setAutoSize(true);
                }
            }
        }

        $spreadsheet->setActiveSheetIndex(0);

        $writer = new Xlsx($spreadsheet);
        $writer->save($filePath);
    }

    private function sanitizeSheetName(string $rawName): string
    {
        $sanitizedName = preg_replace('/[\x00-\x1F\[\]\*\/:\\?]/', '_', $rawName) ?? 'Sheet';
        $sanitizedName = trim($sanitizedName);

        if ($sanitizedName === '') {
            $sanitizedName = 'Sheet';
        }

        return mb_substr($sanitizedName, 0, 31);
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

        $sourceOrigin = strtoupper(trim((string) ($filters['sourceOrigin'] ?? '')));
        if ($sourceOrigin !== '') {
            $whereParts[] = 'entry.source_origin = :sourceOrigin';
            $parameters['sourceOrigin'] = $sourceOrigin;
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

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $whereParts[] = "(LOWER(entry.title) LIKE :search OR LOWER(COALESCE(entry.description, '')) LIKE :search)";
            $parameters['search'] = '%' . mb_strtolower($search) . '%';
        }

        return [implode(' AND ', $whereParts), $parameters];
    }

    /**
     * @return array<string, mixed>
     */
    private function getJobById(int $ownerId, int $jobId): array
    {
        /** @var array<string, mixed>|false $job */
        $job = $this->connection->fetchAssociative(<<<'SQL'
            SELECT
                id,
                export_type AS "exportType",
                filters_json AS "filtersJson",
                status,
                file_name AS "fileName",
                file_path AS "filePath",
                requested_at AS "requestedAt",
                started_at AS "startedAt",
                finished_at AS "finishedAt",
                expires_at AS "expiresAt",
                error_message AS "errorMessage"
            FROM finance_export_job
            WHERE owner_id = :ownerId
              AND id = :jobId
            LIMIT 1
        SQL, [
            'ownerId' => $ownerId,
            'jobId' => $jobId,
        ]);

        if (!is_array($job)) {
            throw new \InvalidArgumentException('Export job not found.');
        }

        return $this->normalizeJobPayload($job);
    }

    /**
     * @param array<string, mixed> $job
     *
     * @return array<string, mixed>
     */
    private function normalizeJobPayload(array $job): array
    {
        $job['filters'] = $this->decodeFilters((string) ($job['filtersJson'] ?? '{}'));

        unset($job['filtersJson']);

        return $job;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeFilters(string $rawJson): array
    {
        if (trim($rawJson) === '') {
            return [];
        }

        try {
            $decoded = json_decode($rawJson, true, flags: \JSON_THROW_ON_ERROR);

            return is_array($decoded) ? $decoded : [];
        } catch (\JsonException) {
            return [];
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
