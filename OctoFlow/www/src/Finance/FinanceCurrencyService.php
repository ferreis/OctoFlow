<?php

namespace App\Finance;

use App\Entity\User;
use Doctrine\DBAL\Connection;

final class FinanceCurrencyService
{
    private const BACEN_BASE_URL = 'https://olinda.bcb.gov.br/olinda/servico/PTAX/versao/v1/odata';
    private const CONNECT_TIMEOUT_SECONDS = 4;
    private const REQUEST_TIMEOUT_SECONDS = 15;
    private const MAX_LOOKBACK_DAYS = 10;

    /**
     * @var list<array{code: string, name: string}>
     */
    private const DEFAULT_CURRENCIES = [
        ['code' => 'USD', 'name' => 'Dolar americano'],
        ['code' => 'EUR', 'name' => 'Euro'],
        ['code' => 'GBP', 'name' => 'Libra esterlina'],
        ['code' => 'ARS', 'name' => 'Peso argentino'],
        ['code' => 'CAD', 'name' => 'Dolar canadense'],
        ['code' => 'AUD', 'name' => 'Dolar australiano'],
        ['code' => 'JPY', 'name' => 'Iene japones'],
        ['code' => 'CHF', 'name' => 'Franco suico'],
        ['code' => 'CNY', 'name' => 'Yuan chines'],
    ];

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * @return array{items: list<array{code: string, name: string}>, source: string}
     */
    public function listCurrencies(): array
    {
        try {
            $response = $this->performGetRequest(self::BACEN_BASE_URL . "/Moedas?\$format=json");
            $payload = $this->decodeJsonResponse($response['body']);
            $items = $this->normalizeCurrencyCatalog($payload);

            if ($items !== []) {
                return [
                    'items' => $items,
                    'source' => 'BACEN',
                ];
            }
        } catch (\Throwable) {
            // fallback below
        }

        return [
            'items' => self::DEFAULT_CURRENCIES,
            'source' => 'DEFAULT',
        ];
    }

    /**
     * @param array<string, mixed> $filters
     *
     * @return array{baseCurrency: string, requestedDate: string, source: string, items: list<array<string, mixed>>}
     */
    public function rates(User $user, array $filters = []): array
    {
        $ownerId = $this->requireOwnerId($user);
        $requestedDate = FinanceInput::normalizeOptionalDate($filters['date'] ?? null) ?? new \DateTimeImmutable('now');
        $requestedDate = new \DateTimeImmutable($requestedDate->format('Y-m-d'));
        $requestedDateLabel = $requestedDate->format('Y-m-d');
        $forceRefresh = FinanceInput::normalizeBoolean($filters['forceRefresh'] ?? false, false);

        $requestedCurrencyCodes = $this->normalizeCurrencyCodes($filters['codes'] ?? null);
        if ($requestedCurrencyCodes === []) {
            $requestedCurrencyCodes = array_map(
                static fn (array $currencyItem): string => $currencyItem['code'],
                self::DEFAULT_CURRENCIES,
            );
        }

        $currenciesCatalog = $this->listCurrencies();
        $currencyNameByCode = [];
        foreach ($currenciesCatalog['items'] as $currencyItem) {
            $currencyNameByCode[$currencyItem['code']] = $currencyItem['name'];
        }

        $ratesItems = [];
        foreach ($requestedCurrencyCodes as $currencyCode) {
            $cachedRate = $this->findPersistedRate($ownerId, $currencyCode, $requestedDateLabel);
            $persistedRate = $forceRefresh ? null : $cachedRate;

            if ($persistedRate === null) {
                $resolvedQuote = $this->resolveLatestQuote($currencyCode, $requestedDate);
                if ($resolvedQuote !== null) {
                    $currencyName = $currencyNameByCode[$currencyCode] ?? $currencyCode;
                    $this->upsertPersistedRate(
                        $ownerId,
                        $currencyCode,
                        $currencyName,
                        $requestedDateLabel,
                        (string) $resolvedQuote['quoteDate'],
                        (string) $resolvedQuote['quoteDateTime'],
                        (float) $resolvedQuote['buyRateBrl'],
                        (float) $resolvedQuote['sellRateBrl'],
                        'API',
                    );

                    $persistedRate = $this->findPersistedRate($ownerId, $currencyCode, $requestedDateLabel);
                }
            }

            if ($persistedRate === null && $cachedRate !== null) {
                $persistedRate = $cachedRate;
            }

            if ($persistedRate !== null) {
                $ratesItems[] = $this->mapPersistedRateToResponseItem($persistedRate, $requestedDateLabel);
                continue;
            }

            $ratesItems[] = [
                'code' => $currencyCode,
                'name' => $currencyNameByCode[$currencyCode] ?? $currencyCode,
                'buyRateBrl' => null,
                'sellRateBrl' => null,
                'rateBrl' => null,
                'requestedDate' => $requestedDateLabel,
                'quoteDate' => null,
                'quoteDateTime' => null,
                'source' => 'NONE',
                'found' => false,
            ];
        }

        return [
            'baseCurrency' => 'BRL',
            'requestedDate' => $requestedDateLabel,
            'source' => $forceRefresh ? 'BACEN_REFRESH' : 'CACHE_DAILY',
            'items' => $ratesItems,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function createManualRate(User $user, array $payload): array
    {
        $ownerId = $this->requireOwnerId($user);
        $quoteDate = FinanceInput::normalizeDate($payload['quoteDate'] ?? 'today', 'quoteDate');
        $quoteDateLabel = $quoteDate->format('Y-m-d');

        $currencyCode = strtoupper(trim((string) ($payload['currencyCode'] ?? '')));
        if (!preg_match('/^[A-Z]{3}$/', $currencyCode)) {
            throw new \InvalidArgumentException('The currency code must be informed with 3 letters.');
        }

        $currencyName = trim((string) ($payload['currencyName'] ?? ''));
        if ($currencyName === '') {
            $currencyName = $currencyCode;
        }

        $rateBrl = FinanceInput::normalizeMoney($payload['rateBrl'] ?? null, 'rateBrl');
        if ($rateBrl <= 0) {
            throw new \InvalidArgumentException('The manual rate must be greater than zero.');
        }

        $quoteDateTime = FinanceInput::normalizeOptionalDate($payload['quoteDateTime'] ?? null);
        if (!$quoteDateTime instanceof \DateTimeImmutable) {
            $quoteDateTime = new \DateTimeImmutable($quoteDateLabel . ' 12:00:00');
        }

        $this->upsertPersistedRate(
            $ownerId,
            $currencyCode,
            $currencyName,
            $quoteDateLabel,
            $quoteDateLabel,
            $quoteDateTime->format('Y-m-d H:i:s'),
            $rateBrl,
            $rateBrl,
            'MANUAL',
        );

        $persistedRate = $this->findPersistedRate($ownerId, $currencyCode, $quoteDateLabel);
        if ($persistedRate === null) {
            throw new \RuntimeException('Failed to save manual currency rate.');
        }

        return $this->mapPersistedRateToResponseItem($persistedRate, $quoteDateLabel);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findPersistedRate(int $ownerId, string $currencyCode, string $quoteDate): ?array
    {
        /** @var array<string, mixed>|false $persistedRate */
        $persistedRate = $this->connection->fetchAssociative(<<<'SQL'
            SELECT
                currency_code AS "currencyCode",
                currency_name AS "currencyName",
                quote_date AS "quoteDate",
                quote_source_date AS "quoteSourceDate",
                quote_datetime AS "quoteDateTime",
                rate_brl AS "rateBrl",
                buy_rate_brl AS "buyRateBrl",
                sell_rate_brl AS "sellRateBrl",
                source
            FROM finance_currency_rate
            WHERE owner_id = :ownerId
              AND currency_code = :currencyCode
              AND quote_date = :quoteDate
            LIMIT 1
        SQL, [
            'ownerId' => $ownerId,
            'currencyCode' => $currencyCode,
            'quoteDate' => $quoteDate,
        ]);

        return is_array($persistedRate) ? $persistedRate : null;
    }

    private function upsertPersistedRate(
        int $ownerId,
        string $currencyCode,
        string $currencyName,
        string $quoteDate,
        string $quoteSourceDate,
        string $quoteDateTime,
        float $buyRateBrl,
        float $sellRateBrl,
        string $source,
    ): void {
        $normalizedBuyRateBrl = round($buyRateBrl, 6);
        $normalizedSellRateBrl = round($sellRateBrl, 6);
        $normalizedRateBrl = round(($normalizedBuyRateBrl + $normalizedSellRateBrl) / 2, 6);
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $this->connection->executeStatement(<<<'SQL'
            INSERT INTO finance_currency_rate (
                owner_id,
                currency_code,
                currency_name,
                quote_date,
                quote_source_date,
                quote_datetime,
                rate_brl,
                buy_rate_brl,
                sell_rate_brl,
                source,
                created_at,
                updated_at
            ) VALUES (
                :ownerId,
                :currencyCode,
                :currencyName,
                :quoteDate,
                :quoteSourceDate,
                :quoteDateTime,
                :rateBrl,
                :buyRateBrl,
                :sellRateBrl,
                :source,
                :createdAt,
                :updatedAt
            )
            ON CONFLICT (owner_id, currency_code, quote_date) DO UPDATE
            SET
                currency_name = EXCLUDED.currency_name,
                quote_source_date = EXCLUDED.quote_source_date,
                quote_datetime = EXCLUDED.quote_datetime,
                rate_brl = EXCLUDED.rate_brl,
                buy_rate_brl = EXCLUDED.buy_rate_brl,
                sell_rate_brl = EXCLUDED.sell_rate_brl,
                source = EXCLUDED.source,
                updated_at = EXCLUDED.updated_at
        SQL, [
            'ownerId' => $ownerId,
            'currencyCode' => $currencyCode,
            'currencyName' => $currencyName,
            'quoteDate' => $quoteDate,
            'quoteSourceDate' => $quoteSourceDate,
            'quoteDateTime' => $quoteDateTime,
            'rateBrl' => $normalizedRateBrl,
            'buyRateBrl' => $normalizedBuyRateBrl,
            'sellRateBrl' => $normalizedSellRateBrl,
            'source' => strtoupper($source),
            'createdAt' => $now,
            'updatedAt' => $now,
        ]);
    }

    /**
     * @param array<string, mixed> $persistedRate
     *
     * @return array<string, mixed>
     */
    private function mapPersistedRateToResponseItem(array $persistedRate, string $requestedDate): array
    {
        $buyRateBrl = isset($persistedRate['buyRateBrl']) ? (float) $persistedRate['buyRateBrl'] : null;
        $sellRateBrl = isset($persistedRate['sellRateBrl']) ? (float) $persistedRate['sellRateBrl'] : null;
        $rateBrl = isset($persistedRate['rateBrl']) ? (float) $persistedRate['rateBrl'] : null;
        $quoteSourceDate = trim((string) ($persistedRate['quoteSourceDate'] ?? ''));
        $quoteDate = trim((string) ($persistedRate['quoteDate'] ?? ''));
        $quoteDateTime = trim((string) ($persistedRate['quoteDateTime'] ?? ''));

        return [
            'code' => (string) ($persistedRate['currencyCode'] ?? ''),
            'name' => (string) ($persistedRate['currencyName'] ?? ''),
            'buyRateBrl' => $buyRateBrl,
            'sellRateBrl' => $sellRateBrl,
            'rateBrl' => $rateBrl,
            'requestedDate' => $requestedDate,
            'quoteDate' => $quoteSourceDate !== '' ? $quoteSourceDate : ($quoteDate !== '' ? $quoteDate : null),
            'quoteDateTime' => $quoteDateTime !== '' ? $quoteDateTime : null,
            'source' => strtoupper((string) ($persistedRate['source'] ?? 'API')),
            'found' => true,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return list<array{code: string, name: string}>
     */
    private function normalizeCurrencyCatalog(array $payload): array
    {
        $value = $payload['value'] ?? null;
        if (!is_array($value)) {
            return [];
        }

        $normalizedItemsByCode = [];

        foreach ($value as $rawCurrencyItem) {
            if (!is_array($rawCurrencyItem)) {
                continue;
            }

            $currencyCode = $this->readStringField(
                $rawCurrencyItem,
                ['simbolo', 'Simbolo', 'codigoMoeda', 'codigo', 'moeda'],
            );
            $currencyCode = strtoupper(trim($currencyCode));
            if (!preg_match('/^[A-Z]{3}$/', $currencyCode)) {
                continue;
            }

            $currencyName = $this->readStringField(
                $rawCurrencyItem,
                ['nomeFormatado', 'NomeFormatado', 'nomeMoeda', 'nome', 'descricao'],
            );
            $currencyName = trim($currencyName);
            if ($currencyName === '') {
                $currencyName = $currencyCode;
            }

            $normalizedItemsByCode[$currencyCode] = [
                'code' => $currencyCode,
                'name' => $currencyName,
            ];
        }

        $normalizedItems = array_values($normalizedItemsByCode);
        usort(
            $normalizedItems,
            static fn (array $leftCurrency, array $rightCurrency): int => strcmp($leftCurrency['code'], $rightCurrency['code']),
        );

        return $normalizedItems;
    }

    /**
     * @return array{buyRateBrl: float, sellRateBrl: float, quoteDate: string, quoteDateTime: string}|null
     */
    private function resolveLatestQuote(string $currencyCode, \DateTimeImmutable $requestedDate): ?array
    {
        for ($dayOffset = 0; $dayOffset <= self::MAX_LOOKBACK_DAYS; $dayOffset++) {
            $candidateDate = $requestedDate->modify(sprintf('-%d day', $dayOffset));
            $dailyQuote = $this->fetchQuoteForDate($currencyCode, $candidateDate);
            if ($dailyQuote === null) {
                continue;
            }

            return [
                'buyRateBrl' => (float) $dailyQuote['buyRateBrl'],
                'sellRateBrl' => (float) $dailyQuote['sellRateBrl'],
                'quoteDate' => $candidateDate->format('Y-m-d'),
                'quoteDateTime' => (string) $dailyQuote['quoteDateTime'],
            ];
        }

        return null;
    }

    /**
     * @return array{buyRateBrl: float, sellRateBrl: float, quoteDateTime: string}|null
     */
    private function fetchQuoteForDate(string $currencyCode, \DateTimeImmutable $quoteDate): ?array
    {
        $endpoint = sprintf(
            "%s/CotacaoMoedaDia(moeda='%s',dataCotacao='%s')?\$format=json",
            self::BACEN_BASE_URL,
            $currencyCode,
            $quoteDate->format('m-d-Y'),
        );

        try {
            $response = $this->performGetRequest($endpoint);
            if ($response['statusCode'] >= 400) {
                return null;
            }

            $payload = $this->decodeJsonResponse($response['body']);
        } catch (\Throwable) {
            return null;
        }

        $value = $payload['value'] ?? null;
        if (!is_array($value) || $value === []) {
            return null;
        }

        $rawQuote = $value[0] ?? null;
        if (!is_array($rawQuote)) {
            return null;
        }

        $buyRateBrl = $rawQuote['cotacaoCompra'] ?? null;
        $sellRateBrl = $rawQuote['cotacaoVenda'] ?? null;
        if (!is_numeric($buyRateBrl) || !is_numeric($sellRateBrl)) {
            return null;
        }

        $quoteDateTime = $this->readStringField($rawQuote, ['dataHoraCotacao', 'DataHoraCotacao']);
        if ($quoteDateTime === '') {
            $quoteDateTime = $quoteDate->format('Y-m-d');
        }

        return [
            'buyRateBrl' => round((float) $buyRateBrl, 6),
            'sellRateBrl' => round((float) $sellRateBrl, 6),
            'quoteDateTime' => $quoteDateTime,
        ];
    }

    /**
     * @param mixed $rawCodes
     *
     * @return list<string>
     */
    private function normalizeCurrencyCodes(mixed $rawCodes): array
    {
        $candidateCodes = [];

        if (is_string($rawCodes)) {
            $candidateCodes = preg_split('/[\s,;]+/', trim($rawCodes)) ?: [];
        } elseif (is_array($rawCodes)) {
            $candidateCodes = $rawCodes;
        }

        $normalizedCodes = [];
        foreach ($candidateCodes as $candidateCode) {
            $currencyCode = strtoupper(trim((string) $candidateCode));
            if (!preg_match('/^[A-Z]{3}$/', $currencyCode)) {
                continue;
            }

            $normalizedCodes[$currencyCode] = $currencyCode;
        }

        return array_values($normalizedCodes);
    }

    /**
     * @return array{statusCode: int, body: string}
     */
    private function performGetRequest(string $url): array
    {
        $curlHandle = curl_init($url);
        if ($curlHandle === false) {
            throw new \RuntimeException('Não foi possível iniciar requisição para cotação.');
        }

        try {
            curl_setopt_array($curlHandle, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT_SECONDS,
                CURLOPT_TIMEOUT => self::REQUEST_TIMEOUT_SECONDS,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_HTTPHEADER => [
                    'Accept: application/json',
                    'User-Agent: OctoFlow-Finance-Currency',
                ],
            ]);

            $rawBody = curl_exec($curlHandle);
            if ($rawBody === false) {
                $curlError = curl_error($curlHandle);
                throw new \RuntimeException(sprintf('Falha ao consultar cotações externas: %s', $curlError));
            }

            return [
                'statusCode' => (int) curl_getinfo($curlHandle, CURLINFO_RESPONSE_CODE),
                'body' => (string) $rawBody,
            ];
        } finally {
            curl_close($curlHandle);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJsonResponse(string $rawBody): array
    {
        $decodedPayload = json_decode($rawBody, true);
        if (!is_array($decodedPayload)) {
            throw new \RuntimeException('Resposta inválida da API de cotação.');
        }

        return $decodedPayload;
    }

    /**
     * @param array<string, mixed> $payload
     * @param list<string> $fieldCandidates
     */
    private function readStringField(array $payload, array $fieldCandidates): string
    {
        foreach ($fieldCandidates as $fieldCandidate) {
            $rawValue = $payload[$fieldCandidate] ?? null;
            if (!is_string($rawValue)) {
                continue;
            }

            $normalizedValue = trim($rawValue);
            if ($normalizedValue !== '') {
                return $normalizedValue;
            }
        }

        return '';
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
