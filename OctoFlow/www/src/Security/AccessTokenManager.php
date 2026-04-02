<?php

namespace App\Security;

use App\Entity\User;
use Lexik\Bundle\JWTAuthenticationBundle\Exception\JWTDecodeFailureException;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\User\UserInterface;

final class AccessTokenManager implements AccessTokenManagerInterface
{
    private const ACTIVE_TOKEN_CACHE_KEY_PREFIX = 'auth.access_token.active.';
    private const BLACKLIST_TOKEN_CACHE_KEY_PREFIX = 'auth.access_token.blacklist.';

    public function __construct(
        private readonly JWTTokenManagerInterface $jwtTokenManager,
        #[Autowire(service: 'access_token_pool')]
        private readonly CacheItemPoolInterface $accessTokenCachePool,
    ) {
    }

    public function issueForUser(UserInterface $user, ?string $previousAccessToken = null): string
    {
        $newAccessToken = (string) $this->jwtTokenManager->create($user);

        if (!$this->registerActiveToken($newAccessToken)) {
            throw new \RuntimeException('Unable to register issued access token in Redis.');
        }

        if ($previousAccessToken === null || trim($previousAccessToken) === '') {
            return $newAccessToken;
        }

        $normalizedPreviousAccessToken = trim($previousAccessToken);
        if (hash_equals($normalizedPreviousAccessToken, $newAccessToken)) {
            return $newAccessToken;
        }

        $this->blacklistToken($normalizedPreviousAccessToken, $user);

        return $newAccessToken;
    }

    public function issueForUserFromRequest(UserInterface $user, Request $request): string
    {
        return $this->issueForUser($user, $this->extractBearerToken($request));
    }

    public function blacklistFromRequest(Request $request, ?UserInterface $expectedUser = null): bool
    {
        $accessToken = $this->extractBearerToken($request);
        if ($accessToken === null) {
            return false;
        }

        return $this->blacklistToken($accessToken, $expectedUser);
    }

    public function blacklistToken(string $accessToken, ?UserInterface $expectedUser = null): bool
    {
        $normalizedAccessToken = trim($accessToken);
        if ($normalizedAccessToken === '') {
            return false;
        }

        $tokenPayload = $this->parseTokenPayload($normalizedAccessToken);
        if ($tokenPayload === null) {
            return false;
        }

        if ($expectedUser !== null && !$this->payloadBelongsToUser($tokenPayload, $expectedUser)) {
            return false;
        }

        $expirationDateTime = $this->resolveExpirationDateTime($tokenPayload);
        if ($expirationDateTime === null || $expirationDateTime <= new \DateTimeImmutable()) {
            $this->accessTokenCachePool->deleteItem($this->buildActiveTokenCacheKey($normalizedAccessToken));
            return false;
        }

        $this->accessTokenCachePool->deleteItem($this->buildActiveTokenCacheKey($normalizedAccessToken));

        $blacklistCacheItem = $this->accessTokenCachePool->getItem($this->buildBlacklistTokenCacheKey($normalizedAccessToken));
        $blacklistCacheItem->set(['status' => 'blacklisted']);
        $blacklistCacheItem->expiresAt($expirationDateTime);
        $this->accessTokenCachePool->save($blacklistCacheItem);

        return true;
    }

    public function validateToken(string $accessToken): bool
    {
        $normalizedAccessToken = trim($accessToken);
        if ($normalizedAccessToken === '') {
            return false;
        }

        if ($this->accessTokenCachePool->hasItem($this->buildBlacklistTokenCacheKey($normalizedAccessToken))) {
            return false;
        }

        return $this->accessTokenCachePool->hasItem($this->buildActiveTokenCacheKey($normalizedAccessToken));
    }

    private function registerActiveToken(string $accessToken): bool
    {
        $tokenPayload = $this->parseTokenPayload($accessToken);
        if ($tokenPayload === null) {
            return false;
        }

        $expirationDateTime = $this->resolveExpirationDateTime($tokenPayload);
        if ($expirationDateTime === null || $expirationDateTime <= new \DateTimeImmutable()) {
            return false;
        }

        $activeCacheItem = $this->accessTokenCachePool->getItem($this->buildActiveTokenCacheKey($accessToken));
        $activeCacheItem->set(['status' => 'active']);
        $activeCacheItem->expiresAt($expirationDateTime);
        $this->accessTokenCachePool->save($activeCacheItem);

        return true;
    }

    /**
     * @param array<string, mixed> $tokenPayload
     */
    private function payloadBelongsToUser(array $tokenPayload, UserInterface $expectedUser): bool
    {
        $userIdentifierClaim = trim((string) $this->jwtTokenManager->getUserIdClaim());
        if ($userIdentifierClaim === '' || !isset($tokenPayload[$userIdentifierClaim])) {
            return false;
        }

        $payloadIdentifier = mb_strtolower(trim((string) $tokenPayload[$userIdentifierClaim]));
        if ($payloadIdentifier === '') {
            return false;
        }

        foreach ($this->resolveKnownIdentifiers($expectedUser) as $knownIdentifier) {
            if (hash_equals($knownIdentifier, $payloadIdentifier)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return string[]
     */
    private function resolveKnownIdentifiers(UserInterface $expectedUser): array
    {
        $knownIdentifiers = [mb_strtolower(trim($expectedUser->getUserIdentifier()))];

        if ($expectedUser instanceof User) {
            foreach ($expectedUser->getEmailAddresses() as $emailAddress) {
                $normalizedEmailAddress = mb_strtolower(trim($emailAddress->getEmail()));
                if ($normalizedEmailAddress !== '') {
                    $knownIdentifiers[] = $normalizedEmailAddress;
                }
            }
        }

        return array_values(array_unique(array_filter($knownIdentifiers)));
    }

    private function buildActiveTokenCacheKey(string $accessToken): string
    {
        return self::ACTIVE_TOKEN_CACHE_KEY_PREFIX . hash('sha256', $accessToken);
    }

    private function buildBlacklistTokenCacheKey(string $accessToken): string
    {
        return self::BLACKLIST_TOKEN_CACHE_KEY_PREFIX . hash('sha256', $accessToken);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function parseTokenPayload(string $accessToken): ?array
    {
        try {
            /** @var array<string, mixed> $tokenPayload */
            $tokenPayload = $this->jwtTokenManager->parse($accessToken);
        } catch (JWTDecodeFailureException) {
            return null;
        }

        return $tokenPayload;
    }

    /**
     * @param array<string, mixed> $tokenPayload
     */
    private function resolveExpirationDateTime(array $tokenPayload): ?\DateTimeImmutable
    {
        $expirationClaim = $tokenPayload['exp'] ?? null;
        if (is_int($expirationClaim)) {
            return new \DateTimeImmutable('@' . $expirationClaim);
        }

        if (is_string($expirationClaim) && ctype_digit($expirationClaim)) {
            return new \DateTimeImmutable('@' . (int) $expirationClaim);
        }

        return null;
    }

    private function extractBearerToken(Request $request): ?string
    {
        $authorizationHeader = trim((string) $request->headers->get('Authorization', ''));
        if ($authorizationHeader === '') {
            return null;
        }

        if (preg_match('/^Bearer\s+(.+)$/i', $authorizationHeader, $matches) !== 1) {
            return null;
        }

        $extractedToken = trim((string) ($matches[1] ?? ''));
        if ($extractedToken === '') {
            return null;
        }

        return $extractedToken;
    }
}
