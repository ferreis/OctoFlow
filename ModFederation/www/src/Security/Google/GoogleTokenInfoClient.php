<?php

namespace App\Security\Google;

use App\Security\Google\Exception\GoogleTokenVerificationException;

final class GoogleTokenInfoClient implements GoogleTokenInfoClientInterface
{
    private const TOKEN_INFO_URL = 'https://oauth2.googleapis.com/tokeninfo?id_token=%s';

    public function fetchTokenInfo(string $idToken): array
    {
        $curlHandle = curl_init(sprintf(self::TOKEN_INFO_URL, rawurlencode($idToken)));
        if ($curlHandle === false) {
            throw new GoogleTokenVerificationException('Could not initialize Google token verification.');
        }

        curl_setopt_array($curlHandle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $rawResponse = curl_exec($curlHandle);
        if ($rawResponse === false) {
            $curlError = curl_error($curlHandle);
            curl_close($curlHandle);

            throw new GoogleTokenVerificationException(sprintf('Failed to contact Google: %s', $curlError));
        }

        $statusCode = (int) curl_getinfo($curlHandle, CURLINFO_RESPONSE_CODE);
        curl_close($curlHandle);

        $decodedResponse = json_decode($rawResponse, true);
        if (!is_array($decodedResponse)) {
            throw new GoogleTokenVerificationException('Google token verification returned an invalid response.');
        }

        if ($statusCode >= 400) {
            $message = $decodedResponse['error_description'] ?? $decodedResponse['error'] ?? 'Google rejected the provided credential.';
            if (!is_string($message) || trim($message) === '') {
                $message = 'Google rejected the provided credential.';
            }

            throw new GoogleTokenVerificationException($message);
        }

        /** @var array<string, mixed> $decodedResponse */
        return $decodedResponse;
    }
}
