<?php

namespace App\Security;

use App\Entity\RefreshToken;
use App\Entity\User;
use App\Repository\RefreshTokenRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;

class RefreshTokenManager
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly RefreshTokenRepository $refreshTokenRepository,
        #[Autowire('%env(int:AUTH_REFRESH_TOKEN_TTL)%')]
        private readonly int $refreshTokenTtl,
        #[Autowire('%env(int:AUTH_REFRESH_TOKEN_MAX_ACTIVE)%')]
        private readonly int $maxActiveTokensPerUser,
    ) {
    }

    public function issue(User $user, Request $request, ?string $parentTokenHash = null): IssuedRefreshToken
    {
        $plainToken = $this->generateToken();
        $tokenHash = $this->hashToken($plainToken);
        $now = new \DateTimeImmutable();
        $expiresAt = $now->modify(sprintf('+%d seconds', $this->normalizeRefreshTokenTtl()));
        $requestContextHashes = $this->resolveRequestContextHashes($request);

        $tokenFamilyId = $parentTokenHash !== null
            ? $this->getTokenFamilyIdByParent($parentTokenHash)
            : $this->generateTokenFamilyId();

        $this->enforceActiveTokenLimit($user);

        $refreshToken = (new RefreshToken())
            ->setUser($user)
            ->setTokenHash($tokenHash)
            ->setFingerprintHash($requestContextHashes['fingerprintHash'])
            ->setUserAgentHash($requestContextHashes['userAgentHash'])
            ->setIpHash($requestContextHashes['ipHash'])
            ->setTokenFamilyId($tokenFamilyId)
            ->setParentTokenHash($parentTokenHash)
            ->setExpiresAt($expiresAt)
            ->setCreatedAt($now)
            ->setRevokedAt(null)
            ->setContextChangedAt(null)
            ->setReuseDetectedAt(null);

        $this->entityManager->persist($refreshToken);
        $this->entityManager->flush();

        return new IssuedRefreshToken($user, $plainToken, $expiresAt);
    }

    /**
     * Evita tokens sem janela de validade em caso de configuração inválida.
     */
    private function normalizeRefreshTokenTtl(): int
    {
        return max(1, $this->refreshTokenTtl);
    }

    private function normalizeMaxActiveTokensPerUser(): int
    {
        return max(1, $this->maxActiveTokensPerUser);
    }

    public function rotate(string $plainToken, Request $request): ?IssuedRefreshToken
    {
        $tokenHash = $this->hashToken($plainToken);
        $now = new \DateTimeImmutable();
        $requestContextHashes = $this->resolveRequestContextHashes($request);

        $existingToken = $this->refreshTokenRepository->findByHash($tokenHash);
        if ($existingToken === null) {
            return null;
        }

        $user = $existingToken->getUser();
        if ($user === null) {
            return null;
        }

        if ($existingToken->isRevoked()) {
            $existingToken->setReuseDetectedAt($now);
            $this->entityManager->flush();
            $this->revokeTokenFamily($existingToken->getTokenFamilyId());

            return null;
        }

        if ($existingToken->isExpired($now)) {
            return null;
        }

        if ($this->isSuspiciousContextChange($existingToken, $requestContextHashes)) {
            $existingToken
                ->setContextChangedAt($now)
                ->setReuseDetectedAt($now)
                ->setRevokedAt($now);
            $this->entityManager->flush();
            $this->revokeTokenFamily($existingToken->getTokenFamilyId());

            return null;
        }

        if ($this->hasAnyContextChange($existingToken, $requestContextHashes)) {
            $existingToken->setContextChangedAt($now);
        }

        $existingToken->setRevokedAt($now);
        $this->entityManager->flush();

        return $this->issue($user, $request, $tokenHash);
    }

    /**
     * Valida se um token é válido sem revogá-lo.
     *
     * Útil para operações de validação que não consomem o token.
     *
     * @return bool True se o token existe, não foi revogado e não expirou
     */
    public function isValid(?string $plainToken): bool
    {
        if ($plainToken === null || $plainToken === '') {
            return false;
        }

        $refreshToken = $this->refreshTokenRepository->findValidByHash($this->hashToken($plainToken));
        return $refreshToken !== null;
    }

    /**
     * Valida se token pertence a um usuário específico.
     *
     * @return bool True se token é válido e pertence ao usuário identificado
     */
    public function isValidForUser(?string $plainToken, ?string $userIdentifier): bool
    {
        if ($plainToken === null || $plainToken === '' || $userIdentifier === null || $userIdentifier === '') {
            return false;
        }

        $refreshToken = $this->refreshTokenRepository->findValidByHash($this->hashToken($plainToken));
        if ($refreshToken === null || $refreshToken->getUser() === null) {
            return false;
        }

        $normalizedUserIdentifier = mb_strtolower(trim($userIdentifier));

        return $this->userOwnsIdentifier($refreshToken->getUser(), $normalizedUserIdentifier);
    }

    /**
     * Revoga um token específico pelo plaintext.
     *
     * @param ?string $plainToken Token em texto plano
     */
    public function revokeByPlainToken(?string $plainToken): void
    {
        if ($plainToken === null || $plainToken === '') {
            return;
        }

        $existing = $this->refreshTokenRepository->findValidByHash($this->hashToken($plainToken));
        if ($existing === null) {
            return;
        }

        $existing->setRevokedAt(new \DateTimeImmutable());
        $this->entityManager->flush();
    }

    public function cleanupExpiredTokens(): int
    {
        return $this->refreshTokenRepository->deleteExpiredTokens();
    }

    /**
     * @param array{fingerprintHash: string, userAgentHash: string, ipHash: string} $requestContextHashes
     */
    private function isSuspiciousContextChange(RefreshToken $existingToken, array $requestContextHashes): bool
    {
        $fingerprintChanged = $this->hashesAreDifferent($existingToken->getFingerprintHash(), $requestContextHashes['fingerprintHash']);
        $userAgentChanged = $this->hashesAreDifferent($existingToken->getUserAgentHash(), $requestContextHashes['userAgentHash']);
        $ipChanged = $this->hashesAreDifferent($existingToken->getIpHash(), $requestContextHashes['ipHash']);

        $existingTokenHasReliableFingerprint = !$this->isMissingFingerprintHash($existingToken->getFingerprintHash());
        if ($existingTokenHasReliableFingerprint && $fingerprintChanged) {
            return true;
        }

        if ($userAgentChanged && $ipChanged) {
            return true;
        }

        return false;
    }

    /**
     * @param array{fingerprintHash: string, userAgentHash: string, ipHash: string} $requestContextHashes
     */
    private function hasAnyContextChange(RefreshToken $existingToken, array $requestContextHashes): bool
    {
        return $this->hashesAreDifferent($existingToken->getFingerprintHash(), $requestContextHashes['fingerprintHash'])
            || $this->hashesAreDifferent($existingToken->getUserAgentHash(), $requestContextHashes['userAgentHash'])
            || $this->hashesAreDifferent($existingToken->getIpHash(), $requestContextHashes['ipHash']);
    }

    private function isMissingFingerprintHash(string $fingerprintHash): bool
    {
        $normalizedFingerprintHash = trim($fingerprintHash);
        if ($normalizedFingerprintHash === '') {
            return true;
        }

        return hash_equals($normalizedFingerprintHash, $this->hashContextValue('fingerprint:none'));
    }

    private function hashesAreDifferent(string $storedHash, string $currentHash): bool
    {
        $normalizedStoredHash = trim($storedHash);
        $normalizedCurrentHash = trim($currentHash);

        if ($normalizedStoredHash === '' || $normalizedCurrentHash === '') {
            return $normalizedStoredHash !== $normalizedCurrentHash;
        }

        return !hash_equals($normalizedStoredHash, $normalizedCurrentHash);
    }

    /**
     * @return array{fingerprintHash: string, userAgentHash: string, ipHash: string}
     */
    private function resolveRequestContextHashes(Request $request): array
    {
        $browserFingerprintHeader = mb_strtolower(trim((string) $request->headers->get('x-browser-fingerprint', '')));
        $browserIdHeader = mb_strtolower(trim((string) $request->headers->get('x-browser-id', '')));
        $normalizedUserAgent = mb_strtolower(trim((string) $request->headers->get('user-agent', '')));
        $normalizedIp = trim((string) $request->getClientIp());

        $fingerprintSource = 'none';
        if ($browserIdHeader !== '') {
            $fingerprintSource = 'browser-id:' . $browserIdHeader;
        } elseif ($browserFingerprintHeader !== '') {
            $fingerprintSource = 'browser-fingerprint:' . $browserFingerprintHeader;
        }

        if ($normalizedUserAgent === '') {
            $normalizedUserAgent = 'unknown';
        }

        if ($normalizedIp === '') {
            $normalizedIp = 'unknown';
        }

        return [
            'fingerprintHash' => $this->hashContextValue('fingerprint:' . $fingerprintSource),
            'userAgentHash' => $this->hashContextValue('user-agent:' . $normalizedUserAgent),
            'ipHash' => $this->hashContextValue('ip:' . $normalizedIp),
        ];
    }

    private function hashContextValue(string $rawValue): string
    {
        return hash('sha256', $rawValue);
    }

    private function enforceActiveTokenLimit(User $user): void
    {
        $activeTokens = $this->refreshTokenRepository->findActiveByUser($user);
        $tokensToRevokeCount = count($activeTokens) - $this->normalizeMaxActiveTokensPerUser() + 1;

        if ($tokensToRevokeCount <= 0) {
            return;
        }

        $revocationDateTime = new \DateTimeImmutable();
        for ($tokenIndex = 0; $tokenIndex < $tokensToRevokeCount; $tokenIndex += 1) {
            $activeToken = $activeTokens[$tokenIndex] ?? null;
            if (!$activeToken instanceof RefreshToken) {
                continue;
            }

            $activeToken
                ->setRevokedAt($revocationDateTime)
                ->setContextChangedAt($revocationDateTime);
        }
    }

    private function revokeTokenFamily(?string $tokenFamilyId): void
    {
        if ($tokenFamilyId === null) {
            return;
        }

        $tokensInFamily = $this->refreshTokenRepository->findByTokenFamilyId($tokenFamilyId);
        $now = new \DateTimeImmutable();

        foreach ($tokensInFamily as $token) {
            if (!$token->isRevoked()) {
                $token->setRevokedAt($now);
            }
        }

        $this->entityManager->flush();
    }

    private function getTokenFamilyIdByParent(string $parentTokenHash): string
    {
        $parent = $this->refreshTokenRepository->findByHash($parentTokenHash);
        if ($parent !== null && $parent->getTokenFamilyId() !== null) {
            return $parent->getTokenFamilyId();
        }

        return $this->generateTokenFamilyId();
    }

    private function generateTokenFamilyId(): string
    {
        return bin2hex(random_bytes(16));
    }

    private function generateToken(): string
    {
        return bin2hex(random_bytes(64));
    }

    private function hashToken(string $plainToken): string
    {
        return hash('sha256', $plainToken);
    }

    private function userOwnsIdentifier(User $user, string $identifier): bool
    {
        $normalizedIdentifier = mb_strtolower(trim($identifier));
        if ($normalizedIdentifier === '') {
            return false;
        }

        if (hash_equals(mb_strtolower(trim($user->getUserIdentifier())), $normalizedIdentifier)) {
            return true;
        }

        foreach ($user->getEmailAddresses() as $emailAddress) {
            if (hash_equals(mb_strtolower(trim($emailAddress->getEmail())), $normalizedIdentifier)) {
                return true;
            }
        }

        return false;
    }
}
