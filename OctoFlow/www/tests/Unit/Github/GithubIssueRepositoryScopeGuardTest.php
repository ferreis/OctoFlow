<?php

namespace App\Tests\Unit\Github;

use App\Entity\User;
use App\Github\Exception\GithubActionForbiddenException;
use App\Github\GithubGraphQLClientInterface;
use App\Github\GithubIssueCacheService;
use App\Github\GithubIssueRepositoryScopeGuard;
use App\Github\GithubProfileService;
use App\Github\GithubRegistryService;
use App\Github\GithubTokenCipher;
use App\Repository\GithubAccountRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class GithubIssueRepositoryScopeGuardTest extends TestCase
{
    private GithubGraphQLClientInterface&MockObject $graphqlClient;
    private GithubIssueCacheService&MockObject $cacheService;
    private GithubRegistryService&MockObject $registryService;
    private GithubProfileService $profileService;

    protected function setUp(): void
    {
        $this->graphqlClient = $this->createMock(GithubGraphQLClientInterface::class);
        $this->cacheService = $this->createMock(GithubIssueCacheService::class);
        $this->registryService = $this->createMock(GithubRegistryService::class);
        $this->profileService = new GithubProfileService(
            $this->createMock(EntityManagerInterface::class),
            new GithubTokenCipher('test-app-secret'),
            $this->registryService,
            $this->createMock(GithubAccountRepository::class),
        );
    }

    public function testAllowsIssueFromEnabledRegisteredRepositoryUsingOwnedCache(): void
    {
        $user = $this->buildTokenOnlyUser();

        $this->cacheService
            ->expects($this->once())
            ->method('findCachedIssue')
            ->with($user, 'issue-node-1')
            ->willReturn([
                'id' => 'issue-node-1',
                'repository' => ['nameWithOwner' => 'Acme/Alpha'],
            ]);

        $this->graphqlClient
            ->expects($this->never())
            ->method('query');

        $this->registryService
            ->expects($this->once())
            ->method('buildCatalog')
            ->with($user, false)
            ->willReturn([
                ['nameWithOwner' => 'acme/alpha', 'isIgnored' => false],
            ]);

        $this->buildGuard()->assertIssueAllowed($user, 'issue-node-1');

        $this->addToAssertionCount(1);
    }

    public function testBlocksIssueFromRepositoryOutsideAuthenticatedUsersAllowList(): void
    {
        $user = $this->buildTokenOnlyUser();

        $this->cacheService
            ->expects($this->once())
            ->method('findCachedIssue')
            ->with($user, 'issue-node-secret')
            ->willReturn(null);

        $this->graphqlClient
            ->expects($this->once())
            ->method('query')
            ->with(
                'ghp_test_token',
                $this->stringContains('OctoFlowIssueRepositoryScope'),
                ['issueId' => 'issue-node-secret']
            )
            ->willReturn([
                'node' => [
                    '__typename' => 'Issue',
                    'repository' => [
                        'nameWithOwner' => 'acme/private-unregistered',
                    ],
                ],
            ]);

        $this->registryService
            ->expects($this->once())
            ->method('buildCatalog')
            ->with($user, false)
            ->willReturn([
                ['nameWithOwner' => 'acme/alpha', 'isIgnored' => false],
            ]);

        $this->expectException(GithubActionForbiddenException::class);
        $this->expectExceptionMessage('not enabled for the authenticated user');

        $this->buildGuard()->assertIssueAllowed($user, 'issue-node-secret');
    }

    private function buildGuard(): GithubIssueRepositoryScopeGuard
    {
        return new GithubIssueRepositoryScopeGuard(
            $this->profileService,
            $this->graphqlClient,
            $this->cacheService,
            $this->registryService,
        );
    }

    private function buildTokenOnlyUser(): User
    {
        $cipher = new GithubTokenCipher('test-app-secret');

        return (new User())
            ->setEmail('owner@example.com')
            ->setPassword('not-used')
            ->setGithubTokenEncrypted($cipher->encrypt('ghp_test_token'));
    }
}
