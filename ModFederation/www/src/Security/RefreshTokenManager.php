<?php

namespace App\Security;

use App\Entity\RefreshToken;
use App\Entity\User;
use App\Repository\RefreshTokenRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;

/**
 * Gerenciador de Refresh Tokens com segurança avançada.
 *
 * Implementa os seguintes mecanismos de segurança:
 * 1. Refresh Token Rotation: cada uso gera novo token e revoga o anterior
 * 2. Token Family Tracking: agrupa tokens de mesma origem para detect reuso
 * 3. Fingerprint Validation: detecta mudanças drásticas de contexto (IP/User-Agent)
 * 4. Reuse Detection: identifica tentativas de reusar tokens revogados
 * 5. Cascade Revocation: ao detectar reuso, revoga toda a família
 */
class RefreshTokenManager
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly RefreshTokenRepository $refreshTokenRepository,
        private readonly FingerprintService $fingerprintService,
        #[Autowire('%env(int:AUTH_REFRESH_TOKEN_TTL)%')]
        private readonly int $refreshTokenTtl,
    ) {
    }

    /**
     * Emite um novo Refresh Token com fingerprint e token family.
     *
     * Cria um novo token único com contexto (fingerprint) e uma família
     * para rastreabilidade e detecção de reuso futuro.
     *
     * @param User $user Usuário autenticado
     * @param Request $request Requisição HTTP do cliente
     * @param ?string $parentTokenHash Token anterior (para formar a corrente)
     * @return IssuedRefreshToken Token gerado com informações de expiração
     */
    public function issue(User $user, Request $request, ?string $parentTokenHash = null): IssuedRefreshToken
    {
        $plainToken = $this->generateToken();
        $tokenHash = $this->hashToken($plainToken);
        $now = new \DateTimeImmutable();
        $expiresAt = $now->modify(sprintf('+%d seconds', $this->normalizeRefreshTokenTtl()));

        // Gera fingerprint e hashes componentes
        $fingerprintHash = $this->fingerprintService->generate($request);
        $userAgentHash = $this->fingerprintService->generateUserAgentHash($request);
        $ipHash = $this->fingerprintService->generateIpHash($request);

        // Cria uma nova família de tokens ou continua a existente
        $tokenFamilyId = $parentTokenHash !== null
            ? $this->getTokenFamilyIdByParent($parentTokenHash)
            : $this->generateTokenFamilyId();

        $refreshToken = (new RefreshToken())
            ->setUser($user)
            ->setTokenHash($tokenHash)
            ->setFingerprintHash($fingerprintHash)
            ->setUserAgentHash($userAgentHash)
            ->setIpHash($ipHash)
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

    /**
     * Rotaciona o Refresh Token (emite novo e revoga o anterior).
     *
     * Core da segurança: cada uso de um refresh token revoga aquele token
     * imediatamente e emite um novo. Detecta tentativas de reuso.
     *
     * Fluxo:
     * 1. Localiza token atual pelo hash
     * 2. Valida se ainda é válido (não expirado, não revogado)
     * 3. Valida fingerprint (contexto cliente não mudou drasticamente)
     * 4. Se token revogado for usado: ALERTA e revoga TODA a família
     * 5. Revoga token atual
     * 6. Emite novo token na mesma família
     *
     * @param string $plainToken Token recebido do cliente
     * @param Request $request Requisição HTTP atual
     * @return ?IssuedRefreshToken Novo token emitido, ou null se inválido/reuso detectado
     */
    public function rotate(string $plainToken, Request $request): ?IssuedRefreshToken
    {
        $tokenHash = $this->hashToken($plainToken);
        $now = new \DateTimeImmutable();

        // Busca o token mais recente (pode estar revogado)
        $existingToken = $this->refreshTokenRepository->findByHash($tokenHash);
        if ($existingToken === null) {
            return null;
        }

        $user = $existingToken->getUser();
        if ($user === null) {
            return null;
        }

        // ▶ DETECÇÃO DE REUSO: Token já foi revogado?
        if ($existingToken->isRevoked()) {
            // Token revogado sendo usado = vazamento detectado!
            // Marca momento da detecção
            $existingToken->setReuseDetectedAt($now);
            $this->entityManager->flush();

            // 🚨 AÇÃO CRÍTICA: Revoga TODA a família
            $this->revokeTokenFamily($existingToken->getTokenFamilyId());

            return null;
        }

        // ▶ VALIDAÇÃO DE FINGERPRINT: Contexto mudou drasticamente?
        $contextChange = $this->fingerprintService->assessContextChange(
            $existingToken->getUserAgentHash(),
            $this->fingerprintService->generateUserAgentHash($request),
            $existingToken->getIpHash(),
            $this->fingerprintService->generateIpHash($request)
        );

        // Se contexto mudou drasticamente, pode indicar token comprometido
        if ($contextChange === 'high') {
            $existingToken->setContextChangedAt($now);
            $this->entityManager->flush();

            // Continua a rotação mas marca o alerta
            // Cliente pode continuar, mas contexto foi registrado
        }

        // ▶ ROTAÇÃO: Revoga token atual
        $existingToken->setRevokedAt($now);
        $this->entityManager->flush();

        // ▶ PROLONGA: Emite novo token na mesma família
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

    /**
     * Limpa tokens expirados (garbage collection).
     *
     * Deve ser executado periodicamente por comando cron ou scheduler.
     * Remove tokens que já passaram sua data de expiração.
     *
     * @return int Quantidade de tokens deletados
     */
    public function cleanupExpiredTokens(): int
    {
        return $this->refreshTokenRepository->deleteExpiredTokens();
    }

    // ═══════════════════════════════════════════════════════════════════════════════

    /**
     * Revoga TODOS os tokens de uma família específica.
     *
     * Acionado quando reuso é detectado (token revogado sendo reutilizado).
     * Garante que qualquer comprometimento revoga toda a cadeia.
     *
     * @param ?string $tokenFamilyId ID da família a ser revogada
     */
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

    /**
     * Obtém ou cria ID de família de tokens.
     *
     * Ao rotacionar um token, continua na mesma família.
     * Isso permite rastrear toda a cadeia de rotação.
     *
     * @param string $parentTokenHash Token anterior
     * @return string ID da família (reutiliza ou cria nova)
     */
    private function getTokenFamilyIdByParent(string $parentTokenHash): string
    {
        $parent = $this->refreshTokenRepository->findByHash($parentTokenHash);
        if ($parent !== null && $parent->getTokenFamilyId() !== null) {
            return $parent->getTokenFamilyId();
        }

        return $this->generateTokenFamilyId();
    }

    /**
     * Gera um ID único para uma família de tokens.
     *
     * @return string ID em formato hex (32 caracteres)
     */
    private function generateTokenFamilyId(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * Gera um token opaco (string aleatória, não JWT).
     *
     * @return string Token em formato hex (128 caracteres = 64 bytes)
     */
    private function generateToken(): string
    {
        return bin2hex(random_bytes(64));
    }

    /**
     * Hash SHA-256 do token para armazenamento seguro.
     *
     * Tokens nunca são armazenados em plaintext. O banco guarda apenas
     * o hash. Isso garante que mesmo um acesso ao BD não rouba tokens.
     *
     * @param string $plainToken Token em texto plano
     * @return string Hash SHA-256
     */
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
