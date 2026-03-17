<?php

namespace App\Tests\Unit\Security\Google;

use App\Security\Google\Exception\GoogleOAuthConfigurationException;
use App\Security\Google\Exception\GoogleTokenVerificationException;
use App\Security\Google\GoogleIdentityVerifier;
use App\Security\Google\GoogleTokenInfoClientInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class GoogleIdentityVerifierTest extends TestCase
{
    private GoogleTokenInfoClientInterface&MockObject $tokenInfoClient;

    protected function setUp(): void
    {
        $this->tokenInfoClient = $this->createMock(GoogleTokenInfoClientInterface::class);
    }

    public function testVerifyIdTokenReturnsNormalizedIdentity(): void
    {
        $this->tokenInfoClient
            ->expects($this->once())
            ->method('fetchTokenInfo')
            ->with('google-id-token')
            ->willReturn([
                'aud' => 'google-client-id',
                'iss' => 'https://accounts.google.com',
                'sub' => 'google-subject',
                'email' => 'USER@Example.com ',
                'email_verified' => 'true',
                'exp' => (string) (time() + 300),
                'name' => ' Test User ',
                'picture' => 'https://example.com/avatar.png',
                'hd' => 'example.com',
            ]);

        $verifier = new GoogleIdentityVerifier($this->tokenInfoClient, 'google-client-id', 'example.com');

        $identity = $verifier->verifyIdToken('google-id-token');

        $this->assertSame('google-subject', $identity->subject);
        $this->assertSame('user@example.com', $identity->email);
        $this->assertTrue($identity->emailVerified);
        $this->assertSame('Test User', $identity->name);
        $this->assertSame('https://example.com/avatar.png', $identity->picture);
        $this->assertSame('example.com', $identity->hostedDomain);
    }

    public function testVerifyIdTokenRejectsMissingConfiguration(): void
    {
        $verifier = new GoogleIdentityVerifier($this->tokenInfoClient, '', '');

        $this->expectException(GoogleOAuthConfigurationException::class);
        $this->expectExceptionMessage('Google OAuth client id is not configured.');

        $verifier->verifyIdToken('google-id-token');
    }

    public function testVerifyIdTokenRejectsDifferentAudience(): void
    {
        $this->tokenInfoClient
            ->method('fetchTokenInfo')
            ->willReturn([
                'aud' => 'different-client-id',
                'iss' => 'https://accounts.google.com',
                'sub' => 'google-subject',
                'email' => 'user@example.com',
                'email_verified' => 'true',
                'exp' => (string) (time() + 300),
            ]);

        $verifier = new GoogleIdentityVerifier($this->tokenInfoClient, 'google-client-id', '');

        $this->expectException(GoogleTokenVerificationException::class);
        $this->expectExceptionMessage('Google token was issued for a different client id.');

        $verifier->verifyIdToken('google-id-token');
    }

    public function testVerifyIdTokenRejectsUnverifiedEmail(): void
    {
        $this->tokenInfoClient
            ->method('fetchTokenInfo')
            ->willReturn([
                'aud' => 'google-client-id',
                'iss' => 'accounts.google.com',
                'sub' => 'google-subject',
                'email' => 'user@example.com',
                'email_verified' => 'false',
                'exp' => (string) (time() + 300),
            ]);

        $verifier = new GoogleIdentityVerifier($this->tokenInfoClient, 'google-client-id', '');

        $this->expectException(GoogleTokenVerificationException::class);
        $this->expectExceptionMessage('Google account email is not verified.');

        $verifier->verifyIdToken('google-id-token');
    }

    public function testVerifyIdTokenRejectsHostedDomainMismatch(): void
    {
        $this->tokenInfoClient
            ->method('fetchTokenInfo')
            ->willReturn([
                'aud' => 'google-client-id',
                'iss' => 'accounts.google.com',
                'sub' => 'google-subject',
                'email' => 'user@example.com',
                'email_verified' => 'true',
                'exp' => (string) (time() + 300),
                'hd' => 'other-domain.com',
            ]);

        $verifier = new GoogleIdentityVerifier($this->tokenInfoClient, 'google-client-id', 'example.com');

        $this->expectException(GoogleTokenVerificationException::class);
        $this->expectExceptionMessage('Google account does not belong to the allowed hosted domain.');

        $verifier->verifyIdToken('google-id-token');
    }
}
