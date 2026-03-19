<?php

namespace App\Tests\Unit\Security;

use App\Entity\RefreshToken;
use App\Entity\User;
use App\Entity\UserEmail;
use App\Repository\RefreshTokenRepository;
use App\Security\RefreshTokenManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class RefreshTokenManagerTest extends TestCase
{
    private EntityManagerInterface&MockObject $entityManager;
    private RefreshTokenRepository&MockObject $refreshTokenRepository;
    private RefreshTokenManager $manager;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->refreshTokenRepository = $this->createMock(RefreshTokenRepository::class);

        $this->manager = new RefreshTokenManager(
            $this->entityManager,
            $this->refreshTokenRepository,
            1209600,
        );
    }

    public function testIsValidForUserAcceptsOldLinkedEmailAfterDefaultEmailChange(): void
    {
        $user = (new User())
            ->setEmail('github@example.com')
            ->setPassword('hashed-password');

        $user->addEmailAddress(
            (new UserEmail())
                ->setEmail('github@example.com')
                ->setProviders(['github'])
                ->setIsPrimary(true)
        );

        $user->addEmailAddress(
            (new UserEmail())
                ->setEmail('gmail@example.com')
                ->setProviders(['google'])
                ->setIsPrimary(false)
        );

        $refreshToken = (new RefreshToken())
            ->setUser($user)
            ->setTokenHash(hash('sha256', 'plain-refresh-token'));

        $this->refreshTokenRepository
            ->expects($this->once())
            ->method('findValidByHash')
            ->with(hash('sha256', 'plain-refresh-token'))
            ->willReturn($refreshToken);

        $this->assertTrue($this->manager->isValidForUser('plain-refresh-token', 'gmail@example.com'));
    }

    public function testIsValidForUserRejectsIdentifierFromAnotherAccount(): void
    {
        $user = (new User())
            ->setEmail('github@example.com')
            ->setPassword('hashed-password');

        $user->addEmailAddress(
            (new UserEmail())
                ->setEmail('github@example.com')
                ->setProviders(['github'])
                ->setIsPrimary(true)
        );

        $refreshToken = (new RefreshToken())
            ->setUser($user)
            ->setTokenHash(hash('sha256', 'plain-refresh-token'));

        $this->refreshTokenRepository
            ->expects($this->once())
            ->method('findValidByHash')
            ->with(hash('sha256', 'plain-refresh-token'))
            ->willReturn($refreshToken);

        $this->assertFalse($this->manager->isValidForUser('plain-refresh-token', 'other@example.com'));
    }

    public function testRotateRejectsExpiredToken(): void
    {
        $user = (new User())
            ->setEmail('owner@example.com')
            ->setPassword('hashed-password');

        $expiredToken = (new RefreshToken())
            ->setUser($user)
            ->setTokenHash(hash('sha256', 'plain-refresh-token'))
            ->setExpiresAt(new \DateTimeImmutable('-1 hour'))
            ->setRevokedAt(null);

        $this->refreshTokenRepository
            ->expects($this->once())
            ->method('findByHash')
            ->with(hash('sha256', 'plain-refresh-token'))
            ->willReturn($expiredToken);

        $this->entityManager
            ->expects($this->never())
            ->method('persist');

        $this->assertNull($this->manager->rotate('plain-refresh-token'));
    }
}
