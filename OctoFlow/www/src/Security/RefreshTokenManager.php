<?php

namespace App\Security;

use App\Entity\RefreshToken;
use App\Entity\User;
use App\Repository\RefreshTokenRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

class RefreshTokenManager
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly RefreshTokenRepository $refreshTokenRepository,
        #[Autowire('%env(int:AUTH_REFRESH_TOKEN_TTL)%')]
        private readonly int $refreshTokenTtl,
    ) {
    }

    public function issue(User $user, ?string $parentTokenHash = null): IssuedRefreshToken
    {
        $plainToken = $this->generateToken();
        $tokenHash = $this->hashToken($plainToken);
        $now = new \DateTimeImmutable();
        $expiresAt = $now->modify(sprintf('+%d seconds', $this->normalizeRefreshTokenTtl()));

        $tokenFamilyId = $parentTokenHash !== null
            ? $this->getTokenFamilyIdByParent($parentTokenHash)
            : $this->generateTokenFamilyId();

        $refreshToken = (new RefreshToken())
            ->setUser($user)
            ->setTokenHash($tokenHash)
            ->setTokenFamilyId($tokenFamilyId)
            ->setParentTokenHash($parentTokenHash)
            ->setExpiresAt($expiresAt)
            ->setCreatedAt($now)
            ->setRevokedAt(null)
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

    public function rotate(string $plainToken): ?IssuedRefreshToken
    {
        $tokenHash = $this->hashToken($plainToken);
        $now = new \DateTimeImmutable();

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

        $existingToken->setRevokedAt($now);
        $this->entityManager->flush();

        return $this->issue($user, $tokenHash);
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
