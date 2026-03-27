<?php

namespace App\Account;

use App\Entity\User;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class GooglePasswordSetupManager
{
    private const MIN_CODE_TTL_SECONDS = 60;
    private const CODE_LENGTH = 8;
    private const CODE_CHARACTERS = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function __construct(
        #[Autowire('%env(int:GOOGLE_PASSWORD_SETUP_CODE_TTL_SECONDS)%')]
        private readonly int $codeTtlSeconds,
    ) {
    }

    public function requiresPasswordSetup(User $user): bool
    {
        return $user->getGoogleSubject() !== null && !$user->isPasswordLoginEnabled();
    }

    public function isEmailCodeValidated(User $user): bool
    {
        return $this->requiresPasswordSetup($user) && $user->getGooglePasswordSetupVerifiedAt() !== null;
    }

    public function hasValidPendingCode(User $user): bool
    {
        if (!$this->requiresPasswordSetup($user) || $this->isEmailCodeValidated($user)) {
            return false;
        }

        $codeHash = $user->getGooglePasswordSetupCodeHash();
        $expiresAt = $user->getGooglePasswordSetupCodeExpiresAt();

        if ($codeHash === null || $expiresAt === null) {
            return false;
        }

        return $expiresAt > new \DateTimeImmutable();
    }

    public function issueCode(User $user): string
    {
        $this->assertGooglePasswordSetupRequired($user);

        $newCode = $this->generateCode();
        $user
            ->setGooglePasswordSetupCodeHash(hash('sha256', $newCode))
            ->setGooglePasswordSetupCodeExpiresAt($this->buildCodeExpiry())
            ->setGooglePasswordSetupVerifiedAt(null);

        return $newCode;
    }

    public function ensureValidCode(User $user): ?string
    {
        $this->assertGooglePasswordSetupRequired($user);

        if ($this->isEmailCodeValidated($user) || $this->hasValidPendingCode($user)) {
            return null;
        }

        return $this->issueCode($user);
    }

    public function verifyCode(User $user, string $code): void
    {
        $this->assertGooglePasswordSetupRequired($user);

        if ($this->isEmailCodeValidated($user)) {
            return;
        }

        $normalizedCode = $this->normalizeCode($code);
        if ($normalizedCode === '') {
            throw new \InvalidArgumentException('Informe o código de validação recebido por e-mail.');
        }

        $storedCodeHash = $user->getGooglePasswordSetupCodeHash();
        $expiresAt = $user->getGooglePasswordSetupCodeExpiresAt();
        if ($storedCodeHash === null || $expiresAt === null) {
            throw new \InvalidArgumentException('Não existe um código ativo. Solicite o reenvio do código.');
        }

        if ($expiresAt <= new \DateTimeImmutable()) {
            $user
                ->setGooglePasswordSetupCodeHash(null)
                ->setGooglePasswordSetupCodeExpiresAt(null);

            throw new \InvalidArgumentException('O código expirou. Solicite um novo código.');
        }

        if (!hash_equals($storedCodeHash, hash('sha256', $normalizedCode))) {
            throw new \InvalidArgumentException('Código inválido. Confira o código e tente novamente.');
        }

        $user
            ->setGooglePasswordSetupVerifiedAt(new \DateTimeImmutable())
            ->setGooglePasswordSetupCodeHash(null)
            ->setGooglePasswordSetupCodeExpiresAt(null);
    }

    public function clearPasswordSetupState(User $user): void
    {
        $user
            ->setGooglePasswordSetupCodeHash(null)
            ->setGooglePasswordSetupCodeExpiresAt(null)
            ->setGooglePasswordSetupVerifiedAt(null);
    }

    public function getCodeTtlInMinutes(): int
    {
        return (int) ceil($this->resolveCodeTtlSeconds() / 60);
    }

    private function assertGooglePasswordSetupRequired(User $user): void
    {
        if ($user->getGoogleSubject() === null) {
            throw new \InvalidArgumentException('Este fluxo é exclusivo para contas criadas com Google.');
        }

        if ($user->isPasswordLoginEnabled()) {
            throw new \InvalidArgumentException('Esta conta já possui login por senha habilitado.');
        }
    }

    private function buildCodeExpiry(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(sprintf('+%d seconds', $this->resolveCodeTtlSeconds()));
    }

    private function resolveCodeTtlSeconds(): int
    {
        return max(self::MIN_CODE_TTL_SECONDS, $this->codeTtlSeconds);
    }

    private function generateCode(): string
    {
        $availableCharacters = self::CODE_CHARACTERS;
        $availableCharactersLength = strlen($availableCharacters);
        $generatedCode = '';

        for ($codeIndex = 0; $codeIndex < self::CODE_LENGTH; $codeIndex++) {
            $randomCharacterIndex = random_int(0, $availableCharactersLength - 1);
            $generatedCode .= $availableCharacters[$randomCharacterIndex];
        }

        return $generatedCode;
    }

    private function normalizeCode(string $rawCode): string
    {
        $trimmedCode = strtoupper(trim($rawCode));
        $withoutSeparators = str_replace([' ', '-'], '', $trimmedCode);

        return preg_replace('/[^A-Z0-9]/', '', $withoutSeparators) ?? '';
    }
}
