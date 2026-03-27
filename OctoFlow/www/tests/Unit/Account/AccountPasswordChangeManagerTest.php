<?php

namespace App\Tests\Unit\Account;

use App\Account\AccountPasswordChangeManager;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

final class AccountPasswordChangeManagerTest extends TestCase
{
    public function testIssueCodeStoresHashAndExpiry(): void
    {
        $manager = new AccountPasswordChangeManager(900);
        $user = (new User())->setEmail('profile@example.com');

        $issuedCode = $manager->issueCode($user);

        $this->assertSame(8, strlen($issuedCode));
        $this->assertSame(hash('sha256', $issuedCode), $user->getPasswordChangeCodeHash());
        $this->assertNotNull($user->getPasswordChangeCodeExpiresAt());
        $this->assertNull($user->getPasswordChangeCodeVerifiedAt());
    }

    public function testVerifyCodeMarksStateAsVerified(): void
    {
        $manager = new AccountPasswordChangeManager(900);
        $user = (new User())->setEmail('profile@example.com');

        $issuedCode = $manager->issueCode($user);
        $manager->verifyCode($user, $issuedCode);

        $this->assertNotNull($user->getPasswordChangeCodeVerifiedAt());
        $this->assertNull($user->getPasswordChangeCodeHash());
        $this->assertNull($user->getPasswordChangeCodeExpiresAt());
    }

    public function testVerifyCodeRejectsExpiredCode(): void
    {
        $manager = new AccountPasswordChangeManager(900);
        $user = (new User())->setEmail('profile@example.com');

        $issuedCode = $manager->issueCode($user);
        $user->setPasswordChangeCodeExpiresAt(new \DateTimeImmutable('-1 minute'));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('expirou');

        $manager->verifyCode($user, $issuedCode);
    }

    public function testEnsureVerifiedThrowsWithoutCodeValidation(): void
    {
        $manager = new AccountPasswordChangeManager(900);
        $user = (new User())->setEmail('profile@example.com');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Valide o código');

        $manager->ensureVerifiedForPasswordChange($user);
    }
}
