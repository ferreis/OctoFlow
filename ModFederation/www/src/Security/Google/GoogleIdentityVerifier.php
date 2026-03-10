<?php

namespace App\Security\Google;

use App\Security\Google\Exception\GoogleOAuthConfigurationException;
use App\Security\Google\Exception\GoogleTokenVerificationException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class GoogleIdentityVerifier
{
    public function __construct(
        private readonly GoogleTokenInfoClientInterface $tokenInfoClient,
        #[Autowire('%env(string:GOOGLE_OAUTH_CLIENT_ID)%')]
        private readonly string $clientId,
        #[Autowire('%env(string:GOOGLE_OAUTH_ALLOWED_HD)%')]
        private readonly string $allowedHostedDomain,
    ) {
    }

    public function verifyIdToken(string $idToken): GoogleIdentity
    {
        $configuredClientId = trim($this->clientId);
        if ($configuredClientId === '') {
            throw new GoogleOAuthConfigurationException('Google OAuth client id is not configured.');
        }

        $payload = $this->tokenInfoClient->fetchTokenInfo(trim($idToken));

        $audience = trim((string) ($payload['aud'] ?? ''));
        if ($audience === '' || !hash_equals($configuredClientId, $audience)) {
            throw new GoogleTokenVerificationException('Google token was issued for a different client id.');
        }

        $issuer = trim((string) ($payload['iss'] ?? ''));
        if (!in_array($issuer, ['accounts.google.com', 'https://accounts.google.com'], true)) {
            throw new GoogleTokenVerificationException('Unexpected Google token issuer.');
        }

        $subject = trim((string) ($payload['sub'] ?? ''));
        if ($subject === '') {
            throw new GoogleTokenVerificationException('Google token does not contain a valid subject.');
        }

        $email = mb_strtolower(trim((string) ($payload['email'] ?? '')));
        if ($email === '') {
            throw new GoogleTokenVerificationException('Google token does not contain a valid email.');
        }

        $emailVerified = filter_var($payload['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if ($emailVerified !== true) {
            throw new GoogleTokenVerificationException('Google account email is not verified.');
        }

        $expiresAt = (int) ($payload['exp'] ?? 0);
        if ($expiresAt <= 0 || $expiresAt < time()) {
            throw new GoogleTokenVerificationException('Google token is expired.');
        }

        $hostedDomain = $this->normalizeOptionalString($payload['hd'] ?? null);
        $requiredHostedDomain = mb_strtolower(trim($this->allowedHostedDomain));
        if ($requiredHostedDomain !== '') {
            if ($hostedDomain === null || mb_strtolower($hostedDomain) !== $requiredHostedDomain) {
                throw new GoogleTokenVerificationException('Google account does not belong to the allowed hosted domain.');
            }
        }

        return new GoogleIdentity(
            $subject,
            $email,
            true,
            $this->normalizeOptionalString($payload['name'] ?? null),
            $this->normalizeOptionalString($payload['picture'] ?? null),
            $hostedDomain,
        );
    }

    private function normalizeOptionalString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $normalized = trim($value);

        return $normalized === '' ? null : $normalized;
    }
}
