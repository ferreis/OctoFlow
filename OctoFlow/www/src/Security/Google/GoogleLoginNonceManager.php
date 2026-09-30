<?php

namespace App\Security\Google;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

final class GoogleLoginNonceManager
{
    private const SESSION_KEY = '_octoflow_google_login_nonces';
    private const MAX_ACTIVE_NONCES = 5;

    public function __construct(
        #[Autowire('%env(int:GOOGLE_LOGIN_NONCE_TTL_SECONDS)%')]
        private readonly int $nonceTtlSeconds,
    ) {
    }

    /**
     * @return array{nonce: string, expiresIn: int}
     */
    public function issue(Request $request): array
    {
        $session = $request->getSession();
        $nonces = $this->cleanup($session);
        $nonce = $this->generateNonce();
        $expiresIn = $this->normalizeTtl();

        $nonces[hash('sha256', $nonce)] = time() + $expiresIn;

        if (count($nonces) > self::MAX_ACTIVE_NONCES) {
            asort($nonces, SORT_NUMERIC);
            $nonces = array_slice($nonces, -self::MAX_ACTIVE_NONCES, null, true);
        }

        $session->set(self::SESSION_KEY, $nonces);

        return [
            'nonce' => $nonce,
            'expiresIn' => $expiresIn,
        ];
    }

    public function consume(Request $request, string $nonce): bool
    {
        $normalizedNonce = trim($nonce);
        if (preg_match('/^[A-Za-z0-9_-]{32,128}$/', $normalizedNonce) !== 1) {
            return false;
        }

        $session = $request->getSession();
        $nonces = $this->cleanup($session);
        $nonceHash = hash('sha256', $normalizedNonce);

        if (!isset($nonces[$nonceHash])) {
            return false;
        }

        unset($nonces[$nonceHash]);
        $session->set(self::SESSION_KEY, $nonces);

        return true;
    }

    /**
     * @return array<string, int>
     */
    private function cleanup(SessionInterface $session): array
    {
        $storedNonces = $session->get(self::SESSION_KEY, []);
        if (!is_array($storedNonces)) {
            $session->set(self::SESSION_KEY, []);

            return [];
        }

        $now = time();
        $validNonces = [];

        foreach ($storedNonces as $nonceHash => $expiresAt) {
            if (!is_string($nonceHash) || preg_match('/^[a-f0-9]{64}$/', $nonceHash) !== 1) {
                continue;
            }

            $normalizedExpiresAt = (int) $expiresAt;
            if ($normalizedExpiresAt > $now) {
                $validNonces[$nonceHash] = $normalizedExpiresAt;
            }
        }

        $session->set(self::SESSION_KEY, $validNonces);

        return $validNonces;
    }

    private function generateNonce(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private function normalizeTtl(): int
    {
        return max(60, min(600, $this->nonceTtlSeconds));
    }
}
