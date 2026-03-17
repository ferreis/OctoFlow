<?php

namespace App\Github;

use App\Github\Exception\GithubGraphQLException;

final class GithubGraphQLClient implements GithubGraphQLClientInterface
{
    private const ENDPOINT_URL = 'https://api.github.com/graphql';

    public function query(string $token, string $query, array $variables = []): array
    {
        try {
            $payload = json_encode([
                'query' => $query,
                'variables' => $variables,
            ], \JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new GithubGraphQLException('Could not encode the GitHub GraphQL request payload.', previous: $exception);
        }

        $curlHandle = curl_init(self::ENDPOINT_URL);
        if ($curlHandle === false) {
            throw new GithubGraphQLException('Could not initialize the GitHub GraphQL request.');
        }

        try {
            curl_setopt_array($curlHandle, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_HTTPHEADER => [
                    'Accept: application/vnd.github+json',
                    'Authorization: Bearer ' . trim($token),
                    'Content-Type: application/json',
                    'User-Agent: OctoFlow-GitHubWorkspace',
                    'X-GitHub-Api-Version: 2022-11-28',
                ],
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);

            $rawResponse = curl_exec($curlHandle);
            if ($rawResponse === false) {
                $curlError = curl_error($curlHandle);

                throw new GithubGraphQLException(sprintf('Failed to contact GitHub: %s', $curlError));
            }

            $statusCode = (int) curl_getinfo($curlHandle, CURLINFO_RESPONSE_CODE);
        } finally {
            curl_close($curlHandle);
        }

        $decodedResponse = json_decode($rawResponse, true);
        if (!is_array($decodedResponse)) {
            throw new GithubGraphQLException('GitHub returned an invalid GraphQL response.');
        }

        if ($statusCode >= 400) {
            throw new GithubGraphQLException($this->extractErrorMessage($decodedResponse, 'GitHub rejected the GraphQL request.'), $statusCode);
        }

        $errors = $decodedResponse['errors'] ?? null;
        if (is_array($errors) && $errors !== []) {
            throw new GithubGraphQLException($this->extractErrorMessage($decodedResponse, 'GitHub GraphQL returned errors.'));
        }

        $data = $decodedResponse['data'] ?? null;
        if (!is_array($data)) {
            throw new GithubGraphQLException('GitHub GraphQL response did not include a data payload.');
        }

        /** @var array<string, mixed> $data */
        return $data;
    }

    /**
     * @param array<string, mixed> $response
     */
    private function extractErrorMessage(array $response, string $fallback): string
    {
        $messages = [];

        $errors = $response['errors'] ?? null;
        if (is_array($errors)) {
            foreach ($errors as $error) {
                $message = is_array($error) ? ($error['message'] ?? null) : null;
                if (is_string($message) && trim($message) !== '') {
                    $messages[] = trim($message);
                }
            }
        }

        $message = $response['message'] ?? null;
        if ($messages === [] && is_string($message) && trim($message) !== '') {
            $messages[] = trim($message);
        }

        return $messages === [] ? $fallback : implode(' ', array_unique($messages));
    }
}
