<?php

namespace App\Github;

use App\Github\Exception\GithubConfigurationException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class GithubTokenCipher
{
    private const CIPHER = 'aes-256-gcm';
    private const TAG_LENGTH = 16;

    public function __construct(
        #[Autowire('%kernel.secret%')]
        private readonly string $applicationSecret,
    ) {
    }

    public function encrypt(string $plainToken): string
    {
        $normalizedToken = trim($plainToken);
        if ($normalizedToken === '') {
            throw new GithubConfigurationException('GitHub token cannot be blank.');
        }

        $key = $this->deriveEncryptionKey();
        $ivLength = openssl_cipher_iv_length(self::CIPHER);
        if ($ivLength === false || $ivLength < 1) {
            throw new GithubConfigurationException('The server could not initialize GitHub token encryption.');
        }

        $iv = random_bytes($ivLength);
        $tag = '';
        $ciphertext = openssl_encrypt($normalizedToken, self::CIPHER, $key, \OPENSSL_RAW_DATA, $iv, $tag, '', self::TAG_LENGTH);

        if (!is_string($ciphertext) || $ciphertext === '') {
            throw new GithubConfigurationException('The server could not encrypt the GitHub token.');
        }

        return base64_encode($iv . $tag . $ciphertext);
    }

    public function decrypt(string $encryptedToken): string
    {
        $payload = base64_decode(trim($encryptedToken), true);
        if (!is_string($payload) || $payload === '') {
            throw new GithubConfigurationException('The stored GitHub token is invalid. Save the profile again.');
        }

        $ivLength = openssl_cipher_iv_length(self::CIPHER);
        if ($ivLength === false || strlen($payload) <= $ivLength + self::TAG_LENGTH) {
            throw new GithubConfigurationException('The stored GitHub token is invalid. Save the profile again.');
        }

        $iv = substr($payload, 0, $ivLength);
        $tag = substr($payload, $ivLength, self::TAG_LENGTH);
        $ciphertext = substr($payload, $ivLength + self::TAG_LENGTH);
        $plaintext = openssl_decrypt($ciphertext, self::CIPHER, $this->deriveEncryptionKey(), \OPENSSL_RAW_DATA, $iv, $tag);

        if (!is_string($plaintext) || trim($plaintext) === '') {
            throw new GithubConfigurationException('The stored GitHub token could not be decrypted. Save the profile again.');
        }

        return trim($plaintext);
    }

    private function deriveEncryptionKey(): string
    {
        $secret = trim($this->applicationSecret);
        if ($secret === '') {
            throw new GithubConfigurationException('APP_SECRET must be configured so GitHub profile tokens can be encrypted safely.');
        }

        return hash_hkdf('sha256', $secret, 32, 'github-profile-token');
    }
}
