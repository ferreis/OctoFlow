<?php

namespace App\Tests\Unit\Security;

use App\Security\Hasher\Argon2idPepperedPasswordHasher;
use PHPUnit\Framework\TestCase;
use Symfony\Component\PasswordHasher\Exception\InvalidPasswordException;

final class Argon2idPepperedPasswordHasherTest extends TestCase
{
    private const PEPPER_A = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';
    private const PEPPER_B = 'abcdef0123456789abcdef0123456789abcdef0123456789abcdef0123456789';

    protected function setUp(): void
    {
        parent::setUp();

        if (!defined('PASSWORD_ARGON2ID')) {
            self::markTestSkipped('Argon2id is not available in this PHP runtime.');
        }
    }

    public function testHashUsesArgon2idWithRandomSaltAndVerifiesPassword(): void
    {
        $hasher = new Argon2idPepperedPasswordHasher(self::PEPPER_A);

        $firstHash = $hasher->hash('correct-horse-battery-staple');
        $secondHash = $hasher->hash('correct-horse-battery-staple');

        self::assertStringStartsWith('octoflow:argon2id:v1:$argon2id$', $firstHash);
        self::assertNotSame($firstHash, $secondHash, 'Argon2id must generate a distinct random salt for each hash.');
        self::assertTrue($hasher->verify($firstHash, 'correct-horse-battery-staple'));
        self::assertFalse($hasher->verify($firstHash, 'wrong-password'));
        self::assertFalse($hasher->needsRehash($firstHash));
    }

    public function testPepperIsRequiredToVerifyCurrentHashes(): void
    {
        $hash = (new Argon2idPepperedPasswordHasher(self::PEPPER_A))->hash('peppered-password');

        self::assertFalse((new Argon2idPepperedPasswordHasher(self::PEPPER_B))->verify($hash, 'peppered-password'));
    }

    public function testLegacyNativeHashIsAcceptedAndMarkedForMigration(): void
    {
        $legacyHash = password_hash('legacy-password', PASSWORD_BCRYPT, ['cost' => 4]);
        self::assertIsString($legacyHash);

        $hasher = new Argon2idPepperedPasswordHasher(self::PEPPER_A);

        self::assertTrue($hasher->verify($legacyHash, 'legacy-password'));
        self::assertTrue($hasher->needsRehash($legacyHash));
    }

    public function testHashRejectsPasswordsAboveSymfonySecurityLimit(): void
    {
        $hasher = new Argon2idPepperedPasswordHasher(self::PEPPER_A);

        $this->expectException(InvalidPasswordException::class);
        $hasher->hash(str_repeat('A', 4097));
    }

    public function testVerifyRejectsPasswordsAboveSymfonySecurityLimit(): void
    {
        $hasher = new Argon2idPepperedPasswordHasher(self::PEPPER_A);
        $hash = $hasher->hash('valid-password');

        self::assertFalse($hasher->verify($hash, str_repeat('A', 4097)));
    }

    public function testPepperMustHaveMinimumLength(): void
    {
        $this->expectException(\LogicException::class);
        new Argon2idPepperedPasswordHasher('too-short');
    }
}
