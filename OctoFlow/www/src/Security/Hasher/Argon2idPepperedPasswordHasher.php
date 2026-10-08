<?php

namespace App\Security\Hasher;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\PasswordHasher\Exception\InvalidPasswordException;
use Symfony\Component\PasswordHasher\Hasher\CheckPasswordLengthTrait;
use Symfony\Component\PasswordHasher\PasswordHasherInterface;

final class Argon2idPepperedPasswordHasher implements PasswordHasherInterface
{
    use CheckPasswordLengthTrait;

    private const HASH_PREFIX = 'octoflow:argon2id:v1:';
    private const MIN_PEPPER_LENGTH = 32;

    private readonly string $pepper;

    public function __construct(
        #[Autowire('%env(file:PASSWORD_PEPPER_FILE)%')]
        string $pepper,
    ) {
        $normalizedPepper = trim($pepper);

        if (strlen($normalizedPepper) < self::MIN_PEPPER_LENGTH) {
            throw new \LogicException(sprintf(
                'PASSWORD_PEPPER_FILE must contain at least %d characters of high-entropy secret data.',
                self::MIN_PEPPER_LENGTH,
            ));
        }

        if (!defined('PASSWORD_ARGON2ID')) {
            throw new \LogicException('Argon2id support is required by the OctoFlow password hasher.');
        }

        $this->pepper = $normalizedPepper;
    }

    public function hash(#[\SensitiveParameter] string $plainPassword): string
    {
        if ($this->isPasswordTooLong($plainPassword)) {
            throw new InvalidPasswordException();
        }

        $hash = password_hash(
            $this->pepperPassword($plainPassword),
            PASSWORD_ARGON2ID,
            $this->argonOptions(),
        );

        if (!is_string($hash) || $hash === '') {
            throw new \RuntimeException('Unable to hash the password with Argon2id.');
        }

        return self::HASH_PREFIX . $hash;
    }

    public function verify(string $hashedPassword, #[\SensitiveParameter] string $plainPassword): bool
    {
        if ($plainPassword === '' || $this->isPasswordTooLong($plainPassword)) {
            return false;
        }

        $currentHash = $this->extractCurrentHash($hashedPassword);
        if ($currentHash !== null) {
            return password_verify($this->pepperPassword($plainPassword), $currentHash);
        }

        return $hashedPassword !== '' && password_verify($plainPassword, $hashedPassword);
    }

    public function needsRehash(string $hashedPassword): bool
    {
        $currentHash = $this->extractCurrentHash($hashedPassword);
        if ($currentHash === null) {
            return true;
        }

        return password_needs_rehash($currentHash, PASSWORD_ARGON2ID, $this->argonOptions());
    }

    /**
     * @return array{memory_cost: int, time_cost: int, threads: int}
     */
    private function argonOptions(): array
    {
        return [
            'memory_cost' => PASSWORD_ARGON2_DEFAULT_MEMORY_COST,
            'time_cost' => PASSWORD_ARGON2_DEFAULT_TIME_COST,
            'threads' => PASSWORD_ARGON2_DEFAULT_THREADS,
        ];
    }

    private function pepperPassword(#[\SensitiveParameter] string $plainPassword): string
    {
        return base64_encode(hash_hmac('sha256', $plainPassword, $this->pepper, true));
    }

    private function extractCurrentHash(string $hashedPassword): ?string
    {
        if (!str_starts_with($hashedPassword, self::HASH_PREFIX)) {
            return null;
        }

        $nativeHash = substr($hashedPassword, strlen(self::HASH_PREFIX));

        return $nativeHash === '' ? null : $nativeHash;
    }
}
