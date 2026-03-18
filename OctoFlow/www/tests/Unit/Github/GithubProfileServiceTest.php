<?php

namespace App\Tests\Unit\Github;

use App\Entity\GithubAccount;
use App\Entity\User;
use App\Github\Exception\GithubConfigurationException;
use App\Github\GithubProfileService;
use App\Github\GithubRegistryService;
use App\Github\GithubTokenCipher;
use App\Repository\GithubAccountRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class GithubProfileServiceTest extends TestCase
{
    private EntityManagerInterface&MockObject $entityManager;
    private GithubRegistryService&MockObject $registryService;
    private GithubAccountRepository&MockObject $githubAccountRepository;
    private GithubTokenCipher $tokenCipher;
    private GithubProfileService $service;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->registryService = $this->createMock(GithubRegistryService::class);
        $this->githubAccountRepository = $this->createMock(GithubAccountRepository::class);
        $this->tokenCipher = new GithubTokenCipher('test-app-secret');
        $this->service = new GithubProfileService(
            $this->entityManager,
            $this->tokenCipher,
            $this->registryService,
            $this->githubAccountRepository,
        );
    }

    public function testUpdateProfileEncryptsTokenAndMarksWorkspaceReady(): void
    {
        $registeredRepository = $this->buildRegisteredRepository();
        $user = (new User())
            ->setEmail('owner@example.com')
            ->setPassword('not-used');
        $primaryAccount = (new GithubAccount())
            ->setOwner($user)
            ->setAccountLogin('acme')
            ->setTokenEncrypted($this->tokenCipher->encrypt('ghp_secret_token'));

        $this->registryService
            ->method('buildCatalog')
            ->willReturn([$registeredRepository]);

        $this->registryService
            ->method('buildAccountCatalog')
            ->willReturn([
                [
                    'id' => 1,
                    'accountLogin' => 'acme',
                    'tokenConfigured' => true,
                    'workspaceReady' => true,
                    'defaultRepositoryKey' => 'acme/delivery-desk',
                    'repositories' => [$registeredRepository],
                ],
            ]);

        $this->registryService
            ->method('resolveDefaultRepository')
            ->willReturn($registeredRepository);

        $this->registryService
            ->method('findFirstOwnedAccount')
            ->willReturn($primaryAccount);

        $this->githubAccountRepository
            ->method('findOneByOwnerAndLogin')
            ->with($user, 'acme')
            ->willReturn(null);

        $this->entityManager
            ->expects($this->exactly(2))
            ->method('flush');

        $profile = $this->service->updateProfile($user, [
            'repositoryOwner' => 'acme',
            'token' => 'ghp_secret_token',
            'clearToken' => false,
        ]);

        $this->assertSame('acme', $profile['repositoryOwner']);
        $this->assertSame('delivery-desk', $profile['repositoryName']);
        $this->assertSame('acme/delivery-desk', $profile['defaultRepositoryKey']);
        $this->assertCount(1, $profile['repositories']);
        $this->assertTrue($profile['tokenConfigured']);
        $this->assertTrue($profile['workspaceReady']);
        $this->assertNotSame('ghp_secret_token', $user->getGithubTokenEncrypted());
        $this->assertCount(1, $profile['accounts']);
        $this->assertSame('ghp_secret_token', $this->tokenCipher->decrypt((string) $user->getGithubTokenEncrypted()));
    }

    public function testBuildRuntimeConfigurationRejectsIncompleteProfile(): void
    {
        $user = (new User())
            ->setEmail('owner@example.com')
            ->setPassword('not-used')
            ->setGithubRepositoryOwner('acme');

        $this->registryService
            ->method('resolveDefaultRepository')
            ->willReturn(null);

        $this->expectException(GithubConfigurationException::class);
        $this->expectExceptionMessage('Register at least one GitHub repository in your profile before using the GitHub workspace.');

        $this->service->buildRuntimeConfiguration($user);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildRegisteredRepository(): array
    {
        return [
            'id' => 10,
            'ownerLogin' => 'acme',
            'name' => 'delivery-desk',
            'nameWithOwner' => 'acme/delivery-desk',
            'url' => 'https://github.com/acme/delivery-desk',
            'isIgnored' => false,
        ];
    }
}
