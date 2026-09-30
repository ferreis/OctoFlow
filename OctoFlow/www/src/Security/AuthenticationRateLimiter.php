<?php

namespace App\Security;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;

final class AuthenticationRateLimiter
{
    private const IDENTITY_KEY_PREFIX = 'auth.login.identity.';
    private const IP_KEY_PREFIX = 'auth.login.ip.';

    public function __construct(
        #[Autowire(service: 'auth_rate_limit_pool')]
        private readonly CacheItemPoolInterface $cachePool,
        #[Autowire('%env(int:AUTH_LOGIN_RATE_LIMIT_MAX_ATTEMPTS)%')]
        private readonly int $maxIdentityAttempts,
        #[Autowire('%env(int:AUTH_LOGIN_RATE_LIMIT_IP_MAX_ATTEMPTS)%')]
        private readonly int $maxIpAttempts,
        #[Autowire('%env(int:AUTH_LOGIN_RATE_LIMIT_WINDOW_SECONDS)%')]
        private readonly int $windowSeconds,
    ) {
    }

    public function retryAfter(Request $request, string $identity): int
    {
        $normalizedIdentity = $this->normalizeIdentity($identity);
        if ($normalizedIdentity === '') {
            return 0;
        }

        return max(
            $this->retryAfterForKey($this->buildIdentityKey($normalizedIdentity), $this->normalizeIdentityLimit()),
            $this->retryAfterForKey($this->buildIpKey($request), $this->normalizeIpLimit()),
        );
    }

    public function recordFailure(Request $request, string $identity): void
    {
        $normalizedIdentity = $this->normalizeIdentity($identity);
        if ($normalizedIdentity === '') {
            return;
        }

        $this->increment($this->buildIdentityKey($normalizedIdentity));
        $this->increment($this->buildIpKey($request));
    }

    public function clearIdentity(string $identity): void
    {
        $normalizedIdentity = $this->normalizeIdentity($identity);
        if ($normalizedIdentity === '') {
            return;
        }

        $this->cachePool->deleteItem($this->buildIdentityKey($normalizedIdentity));
    }

    private function retryAfterForKey(string $key, int $limit): int
    {
        $item = $this->cachePool->getItem($key);
        if (!$item->isHit()) {
            return 0;
        }

        $payload = $item->get();
        if (!is_array($payload)) {
            $this->cachePool->deleteItem($key);

            return 0;
        }

        $count = max(0, (int) ($payload['count'] ?? 0));
        $expiresAt = max(0, (int) ($payload['expiresAt'] ?? 0));
        $now = time();

        if ($expiresAt <= $now) {
            $this->cachePool->deleteItem($key);

            return 0;
        }

        if ($count < $limit) {
            return 0;
        }

        return max(1, $expiresAt - $now);
    }

    private function increment(string $key): void
    {
        $item = $this->cachePool->getItem($key);
        $now = time();
        $windowSeconds = $this->normalizeWindowSeconds();
        $payload = $item->isHit() ? $item->get() : null;

        $count = 0;
        $expiresAt = $now + $windowSeconds;

        if (is_array($payload)) {
            $storedExpiresAt = (int) ($payload['expiresAt'] ?? 0);
            if ($storedExpiresAt > $now) {
                $count = max(0, (int) ($payload['count'] ?? 0));
                $expiresAt = $storedExpiresAt;
            }
        }

        $item->set([
            'count' => $count + 1,
            'expiresAt' => $expiresAt,
        ]);
        $item->expiresAfter(max(1, $expiresAt - $now));
        $this->cachePool->save($item);
    }

    private function buildIdentityKey(string $identity): string
    {
        return self::IDENTITY_KEY_PREFIX . hash('sha256', $identity);
    }

    private function buildIpKey(Request $request): string
    {
        $clientIp = trim((string) $request->getClientIp());
        if ($clientIp === '') {
            $clientIp = 'unknown';
        }

        return self::IP_KEY_PREFIX . hash('sha256', $clientIp);
    }

    private function normalizeIdentity(string $identity): string
    {
        return mb_strtolower(trim($identity));
    }

    private function normalizeIdentityLimit(): int
    {
        return max(1, min(100, $this->maxIdentityAttempts));
    }

    private function normalizeIpLimit(): int
    {
        return max($this->normalizeIdentityLimit(), min(1000, $this->maxIpAttempts));
    }

    private function normalizeWindowSeconds(): int
    {
        return max(60, min(86400, $this->windowSeconds));
    }
}
