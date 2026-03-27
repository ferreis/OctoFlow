<?php

namespace App\Account;

use App\Entity\User;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class AccountPasswordChangeManager
{
    private const MIN_CODE_TTL_SECONDS = 60;
    private const CODE_LENGTH = 8;
    private const CODE_CHARACTERS = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function __construct(
        #[Autowire('%env(int:PASSWORD_CHANGE_CODE_TTL_SECONDS)%')]
        private readonly int $codeTtlSeconds,
    ) {
    }

    public function issueCode(User $user): string
    {
        $generatedCode = $this->generateCode();
        $user
            ->setPasswordChangeCodeHash(hash('sha256', $generatedCode))
            ->setPasswordChangeCodeExpiresAt($this->buildCodeExpiry())
            ->setPasswordChangeCodeVerifiedAt(null);

        return $generatedCode;
    }

    public function verifyCode(User $user, string $providedCode): void
    {
        $normalizedCode = $this->normalizeCode($providedCode);
        if ($normalizedCode === '') {
            throw new \InvalidArgumentException('Informe o código de validação recebido por e-mail.');
        }

        $storedCodeHash = $user->getPasswordChangeCodeHash();
        $codeExpiresAt = $user->getPasswordChangeCodeExpiresAt();
        if ($storedCodeHash === null || $codeExpiresAt === null) {
            throw new \InvalidArgumentException('Não existe um código ativo. Solicite um novo código.');
        }

        if ($codeExpiresAt <= new \DateTimeImmutable()) {
            $user
                ->setPasswordChangeCodeHash(null)
                ->setPasswordChangeCodeExpiresAt(null);

            throw new \InvalidArgumentException('O código expirou. Solicite um novo código.');
        }

        if (!hash_equals($storedCodeHash, hash('sha256', $normalizedCode))) {
            throw new \InvalidArgumentException('Código inválido. Confira o código e tente novamente.');
        }

        $user
            ->setPasswordChangeCodeVerifiedAt(new \DateTimeImmutable())
            ->setPasswordChangeCodeHash(null)
            ->setPasswordChangeCodeExpiresAt(null);
    }

    public function ensureVerifiedForPasswordChange(User $user): void
    {
        if ($user->getPasswordChangeCodeVerifiedAt() === null) {
            throw new \InvalidArgumentException('Valide o código recebido por e-mail antes de alterar a senha.');
        }
    }

    public function clearState(User $user): void
    {
        $user
            ->setPasswordChangeCodeHash(null)
            ->setPasswordChangeCodeExpiresAt(null)
            ->setPasswordChangeCodeVerifiedAt(null);
    }

    public function getCodeTtlInMinutes(): int
    {
        return (int) ceil($this->resolveCodeTtlSeconds() / 60);
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
