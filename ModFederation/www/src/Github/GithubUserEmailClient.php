<?php

namespace App\Github;

use App\Github\Exception\GithubApiException;

final class GithubUserEmailClient
{
    private const ENDPOINT_URL = 'https://api.github.com/user/emails';

    /**
     * @return list<array{email: string, primary: bool, verified: bool, visibility: string|null}>
     */
    public function fetchEmails(string $token): array
    {
        $curlHandle = curl_init(self::ENDPOINT_URL);
        if ($curlHandle === false) {
            throw new GithubApiException('Could not initialize the GitHub email request.');
        }

        try {
            curl_setopt_array($curlHandle, [
                CURLOPT_HTTPGET => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_HTTPHEADER => [
                    'Accept: application/vnd.github+json',
                    'Authorization: Bearer ' . trim($token),
                    'User-Agent: ModFederation-GitHubWorkspace',
                    'X-GitHub-Api-Version: 2022-11-28',
                ],
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);

            $rawResponse = curl_exec($curlHandle);
            if ($rawResponse === false) {
                $curlError = curl_error($curlHandle);

                throw new GithubApiException(sprintf('Failed to contact GitHub: %s', $curlError));
            }

            $statusCode = (int) curl_getinfo($curlHandle, CURLINFO_RESPONSE_CODE);
        } finally {
            curl_close($curlHandle);
        }

        $decodedResponse = json_decode($rawResponse, true);
        if ($statusCode >= 400) {
            $response = is_array($decodedResponse) ? $decodedResponse : [];

            throw new GithubApiException($this->extractErrorMessage($response, 'GitHub rejected the email request.'), $statusCode);
        }

        if (!is_array($decodedResponse)) {
            throw new GithubApiException('GitHub returned an invalid response while loading account emails.');
        }

        $emails = [];
        foreach ($decodedResponse as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $email = mb_strtolower(trim((string) ($entry['email'] ?? '')));
            if ($email === '') {
                continue;
            }

            $emails[] = [
                'email' => $email,
                'primary' => (bool) ($entry['primary'] ?? false),
                'verified' => (bool) ($entry['verified'] ?? false),
                'visibility' => is_string($entry['visibility'] ?? null) ? trim((string) $entry['visibility']) : null,
            ];
        }

        return $emails;
    }

    /**
     * @param array<string, mixed> $response
     */
    private function extractErrorMessage(array $response, string $fallback): string
    {
        $message = $response['message'] ?? null;
        if (is_string($message) && trim($message) !== '') {
            return trim($message);
        }

        return $fallback;
    }
}
