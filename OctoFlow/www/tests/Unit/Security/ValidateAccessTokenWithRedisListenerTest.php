<?php

namespace App\Tests\Unit\Security;

use App\EventListener\ValidateAccessTokenWithRedisListener;
use App\Entity\User;
use App\Security\AccessTokenManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTAuthenticatedEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Exception\InvalidTokenException;
use Lexik\Bundle\JWTAuthenticationBundle\Security\Authenticator\Token\JWTPostAuthenticationToken;
use PHPUnit\Framework\TestCase;

final class ValidateAccessTokenWithRedisListenerTest extends TestCase
{
    public function testAllowsRequestWhenRedisValidationSucceeds(): void
    {
        $accessTokenManager = $this->createMock(AccessTokenManagerInterface::class);
        $accessTokenManager
            ->expects($this->once())
            ->method('validateToken')
            ->with('active-access-token')
            ->willReturn(true);

        $listener = new ValidateAccessTokenWithRedisListener($accessTokenManager);
        $listener($this->createJwtAuthenticatedEvent('active-access-token'));
    }

    public function testRejectsRequestWhenRedisValidationFails(): void
    {
        $accessTokenManager = $this->createMock(AccessTokenManagerInterface::class);
        $accessTokenManager
            ->expects($this->once())
            ->method('validateToken')
            ->with('blocked-access-token')
            ->willReturn(false);

        $listener = new ValidateAccessTokenWithRedisListener($accessTokenManager);

        $this->expectException(InvalidTokenException::class);
        $listener($this->createJwtAuthenticatedEvent('blocked-access-token'));
    }

    private function createJwtAuthenticatedEvent(string $accessToken): JWTAuthenticatedEvent
    {
        $authenticatedUser = (new User())
            ->setEmail('owner@example.com')
            ->setRoles(['ROLE_USER']);
        $securityToken = new JWTPostAuthenticationToken($authenticatedUser, 'main', $authenticatedUser->getRoles(), $accessToken);

        return new JWTAuthenticatedEvent(['username' => 'owner@example.com'], $securityToken);
    }
}
