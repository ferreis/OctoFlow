<?php

namespace App\EventListener;

use App\Security\AccessTokenManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTAuthenticatedEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Events;
use Lexik\Bundle\JWTAuthenticationBundle\Exception\InvalidTokenException;
use Lexik\Bundle\JWTAuthenticationBundle\Security\Authenticator\Token\JWTPostAuthenticationToken;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener(event: Events::JWT_AUTHENTICATED, priority: 20)]
final class ValidateAccessTokenWithRedisListener
{
    public function __construct(
        private readonly AccessTokenManagerInterface $accessTokenManager,
    ) {
    }

    public function __invoke(JWTAuthenticatedEvent $event): void
    {
        $authenticatedToken = $event->getToken();
        if (!$authenticatedToken instanceof JWTPostAuthenticationToken) {
            throw new InvalidTokenException('Unsupported JWT token type.');
        }

        $tokenCredentials = $authenticatedToken->getCredentials();
        if (!is_string($tokenCredentials) || trim($tokenCredentials) === '') {
            throw new InvalidTokenException('Missing JWT access token credentials.');
        }

        if (!$this->accessTokenManager->validateToken($tokenCredentials)) {
            throw new InvalidTokenException('JWT token is invalid in Redis.');
        }
    }
}
