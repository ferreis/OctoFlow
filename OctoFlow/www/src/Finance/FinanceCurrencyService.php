<?php

namespace App\Finance;

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
    public function rates(array $filters = []): array
    {
        $requestedDate = FinanceInput::normalizeOptionalDate($filters['date'] ?? null) ?? new \DateTimeImmutable('now');
        $requestedDate = new \DateTimeImmutable($requestedDate->format('Y-m-d'));

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
            $resolvedQuote = $this->resolveLatestQuote($currencyCode, $requestedDate);

            $ratesItems[] = [
                'code' => $currencyCode,
                'name' => $currencyNameByCode[$currencyCode] ?? $currencyCode,
                'buyRateBrl' => $resolvedQuote['buyRateBrl'] ?? null,
                'sellRateBrl' => $resolvedQuote['sellRateBrl'] ?? null,
                'requestedDate' => $requestedDate->format('Y-m-d'),
                'quoteDate' => $resolvedQuote['quoteDate'] ?? null,
                'quoteDateTime' => $resolvedQuote['quoteDateTime'] ?? null,
                'found' => $resolvedQuote !== null,
            ];
        }

        return [
            'baseCurrency' => 'BRL',
            'requestedDate' => $requestedDate->format('Y-m-d'),
            'source' => 'BACEN',
            'items' => $ratesItems,
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
}
