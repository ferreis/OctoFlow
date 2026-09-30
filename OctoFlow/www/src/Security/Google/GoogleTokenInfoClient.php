<?php

namespace App\Security\Google;

use App\Security\Google\Exception\GoogleTokenVerificationException;

final class GoogleTokenInfoClient implements GoogleTokenInfoClientInterface
{
    private const TOKEN_INFO_URL = 'https://oauth2.googleapis.com/tokeninfo?id_token=%s';

    public function fetchTokenInfo(string $idToken): array
    {
        $normalizedIdToken = trim($idToken);
        if ($normalizedIdToken === '' || strlen($normalizedIdToken) > 8192) {
            throw new GoogleTokenVerificationException('Google rejected the provided credential.');
        }

        $curlHandle = curl_init(sprintf(self::TOKEN_INFO_URL, rawurlencode($normalizedIdToken)));
        if ($curlHandle === false) {
            throw new GoogleTokenVerificationException('Google token verification is temporarily unavailable.');
        }

        curl_setopt_array($curlHandle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        ]);

        $rawResponse = curl_exec($curlHandle);
        if ($rawResponse === false) {
            throw new GoogleTokenVerificationException('Google token verification is temporarily unavailable.');
        }

        $statusCode = (int) curl_getinfo($curlHandle, CURLINFO_RESPONSE_CODE);
        if ($statusCode < 200 || $statusCode >= 300) {
            throw new GoogleTokenVerificationException('Google rejected the provided credential.');
        }

        try {
            $decodedResponse = json_decode($rawResponse, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new GoogleTokenVerificationException('Google token verification returned an invalid response.');
        }

        if (!is_array($decodedResponse)) {
            throw new GoogleTokenVerificationException('Google token verification returned an invalid response.');
        }

        /** @var array<string, mixed> $decodedResponse */
        return $decodedResponse;
    }
}
