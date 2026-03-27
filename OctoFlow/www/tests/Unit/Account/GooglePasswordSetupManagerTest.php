<?php

namespace App\Tests\Unit\Account;

use App\Account\GooglePasswordSetupManager;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

final class GooglePasswordSetupManagerTest extends TestCase
{
    public function testIssueCodeCreatesHashAndExpiryForGoogleUser(): void
    {
        $manager = new GooglePasswordSetupManager(900);
        $user = (new User())
            ->setEmail('google-user@example.com')
            ->setGoogleSubject('google-subject-123')
            ->setPasswordLoginEnabled(false);

        $issuedCode = $manager->issueCode($user);

        $this->assertSame(8, strlen($issuedCode));
        $this->assertSame(hash('sha256', $issuedCode), $user->getGooglePasswordSetupCodeHash());
        $this->assertNotNull($user->getGooglePasswordSetupCodeExpiresAt());
        $this->assertGreaterThan(new \DateTimeImmutable(), $user->getGooglePasswordSetupCodeExpiresAt());
        $this->assertNull($user->getGooglePasswordSetupVerifiedAt());
    }

    public function testVerifyCodeValidatesEmailAndClearsPendingCode(): void
    {
        $manager = new GooglePasswordSetupManager(900);
        $user = (new User())
            ->setEmail('google-user@example.com')
            ->setGoogleSubject('google-subject-123')
            ->setPasswordLoginEnabled(false);

        $issuedCode = $manager->issueCode($user);
        $manager->verifyCode($user, $issuedCode);

        $this->assertNotNull($user->getGooglePasswordSetupVerifiedAt());
        $this->assertNull($user->getGooglePasswordSetupCodeHash());
        $this->assertNull($user->getGooglePasswordSetupCodeExpiresAt());
        $this->assertTrue($manager->isEmailCodeValidated($user));
    }

    public function testVerifyCodeRejectsExpiredCode(): void
    {
        $manager = new GooglePasswordSetupManager(900);
        $user = (new User())
            ->setEmail('google-user@example.com')
            ->setGoogleSubject('google-subject-123')
            ->setPasswordLoginEnabled(false);

        $issuedCode = $manager->issueCode($user);
        $user->setGooglePasswordSetupCodeExpiresAt(new \DateTimeImmutable('-2 minutes'));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('expirou');

        $manager->verifyCode($user, $issuedCode);
    }

    public function testIssueCodeRejectsNonGoogleUser(): void
    {
        $manager = new GooglePasswordSetupManager(900);
        $user = (new User())
            ->setEmail('local-user@example.com')
            ->setPasswordLoginEnabled(false);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('exclusivo para contas criadas com Google');

        $manager->issueCode($user);
    }
}
