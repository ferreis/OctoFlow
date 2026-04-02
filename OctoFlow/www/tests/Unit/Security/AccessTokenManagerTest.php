<?php

namespace App\Tests\Unit\Security;

use App\Entity\User;
use App\Security\AccessTokenManager;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\Request;

final class AccessTokenManagerTest extends TestCase
{
    public function testIssueForUserRegistersNewTokenAndBlacklistsPreviousToken(): void
    {
        $tokenExpirationTimestamp = time() + 600;
        $user = (new User())->setEmail('owner@example.com');
        $cachePool = new ArrayAdapter();
        $jwtTokenManager = $this->createJwtTokenManagerMock(
            $user,
            'new-access-token',
            [
                'new-access-token' => [
                    'exp' => $tokenExpirationTimestamp,
                    'username' => 'owner@example.com',
                ],
                'old-access-token' => [
                    'exp' => $tokenExpirationTimestamp,
                    'username' => 'owner@example.com',
                ],
            ],
        );

        $accessTokenManager = new AccessTokenManager($jwtTokenManager, $cachePool);
        $issuedAccessToken = $accessTokenManager->issueForUser($user, 'old-access-token');

        $this->assertSame('new-access-token', $issuedAccessToken);
        $this->assertTrue($accessTokenManager->validateToken('new-access-token'));
        $this->assertFalse($accessTokenManager->validateToken('old-access-token'));
    }

    public function testIssueForUserFromRequestBlacklistsBearerTokenFromHeader(): void
    {
        $tokenExpirationTimestamp = time() + 600;
        $user = (new User())->setEmail('owner@example.com');
        $cachePool = new ArrayAdapter();
        $jwtTokenManager = $this->createJwtTokenManagerMock(
            $user,
            'new-access-token',
            [
                'new-access-token' => [
                    'exp' => $tokenExpirationTimestamp,
                    'username' => 'owner@example.com',
                ],
                'old-header-token' => [
                    'exp' => $tokenExpirationTimestamp,
                    'username' => 'owner@example.com',
                ],
            ],
        );

        $request = Request::create('/auth/refresh', 'POST');
        $request->headers->set('Authorization', 'Bearer old-header-token');

        $accessTokenManager = new AccessTokenManager($jwtTokenManager, $cachePool);
        $issuedAccessToken = $accessTokenManager->issueForUserFromRequest($user, $request);

        $this->assertSame('new-access-token', $issuedAccessToken);
        $this->assertTrue($accessTokenManager->validateToken('new-access-token'));
        $this->assertFalse($accessTokenManager->validateToken('old-header-token'));
    }

    public function testBlacklistFromRequestRejectsForeignTokenWhenExpectedUserIsDifferent(): void
    {
        $tokenExpirationTimestamp = time() + 600;
        $ownerUser = (new User())->setEmail('owner@example.com');
        $cachePool = new ArrayAdapter();
        $jwtTokenManager = $this->createJwtTokenManagerMock(
            $ownerUser,
            'new-access-token',
            [
                'new-access-token' => [
                    'exp' => $tokenExpirationTimestamp,
                    'username' => 'owner@example.com',
                ],
                'foreign-access-token' => [
                    'exp' => $tokenExpirationTimestamp,
                    'username' => 'foreign@example.com',
                ],
            ],
        );

        $accessTokenManager = new AccessTokenManager($jwtTokenManager, $cachePool);
        $accessTokenManager->issueForUser($ownerUser);

        $request = Request::create('/auth/logout', 'POST');
        $request->headers->set('Authorization', 'Bearer foreign-access-token');
        $blacklistResult = $accessTokenManager->blacklistFromRequest($request, $ownerUser);

        $this->assertFalse($blacklistResult);
        $this->assertTrue($accessTokenManager->validateToken('new-access-token'));
    }

    public function testBlacklistFromRequestRevokesCurrentToken(): void
    {
        $tokenExpirationTimestamp = time() + 600;
        $user = (new User())->setEmail('owner@example.com');
        $cachePool = new ArrayAdapter();
        $jwtTokenManager = $this->createJwtTokenManagerMock(
            $user,
            'new-access-token',
            [
                'new-access-token' => [
                    'exp' => $tokenExpirationTimestamp,
                    'username' => 'owner@example.com',
                ],
            ],
        );

        $accessTokenManager = new AccessTokenManager($jwtTokenManager, $cachePool);
        $issuedAccessToken = $accessTokenManager->issueForUser($user);
        $this->assertTrue($accessTokenManager->validateToken($issuedAccessToken));

        $request = Request::create('/auth/logout', 'POST');
        $request->headers->set('Authorization', sprintf('Bearer %s', $issuedAccessToken));

        $blacklistResult = $accessTokenManager->blacklistFromRequest($request, $user);

        $this->assertTrue($blacklistResult);
        $this->assertFalse($accessTokenManager->validateToken($issuedAccessToken));
    }

    /**
     * @param array<string, array<string, mixed>> $tokenPayloadByToken
     */
    private function createJwtTokenManagerMock(
        User $user,
        string $issuedToken,
        array $tokenPayloadByToken,
    ): JWTTokenManagerInterface&MockObject {
        $jwtTokenManager = $this->createMock(JWTTokenManagerInterface::class);
        $jwtTokenManager
            ->method('create')
            ->with($user)
            ->willReturn($issuedToken);

        $jwtTokenManager
            ->method('parse')
            ->willReturnCallback(static function (string $token) use ($tokenPayloadByToken): array {
                if (!isset($tokenPayloadByToken[$token])) {
                    throw new \RuntimeException(sprintf('Unexpected token parse call: %s', $token));
                }

                /** @var array<string, mixed> $tokenPayload */
                $tokenPayload = $tokenPayloadByToken[$token];

                return $tokenPayload;
            });

        $jwtTokenManager
            ->method('getUserIdClaim')
            ->willReturn('username');

        return $jwtTokenManager;
    }
}
