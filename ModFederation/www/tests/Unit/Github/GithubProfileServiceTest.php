<?php

namespace App\Tests\Unit\Github;

use App\Entity\User;
use App\Github\Exception\GithubConfigurationException;
use App\Github\GithubProfileService;
use App\Github\GithubRegistryService;
use App\Github\GithubTokenCipher;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class GithubProfileServiceTest extends TestCase
{
    private EntityManagerInterface&MockObject $entityManager;
    private GithubRegistryService&MockObject $registryService;
    private GithubProfileService $service;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->registryService = $this->createMock(GithubRegistryService::class);
        $this->service = new GithubProfileService(
            $this->entityManager,
            new GithubTokenCipher('test-app-secret'),
            $this->registryService
        );
    }

    public function testUpdateProfileEncryptsTokenAndMarksWorkspaceReady(): void
    {
        $registeredRepository = $this->buildRegisteredRepository();
        $user = (new User())
            ->setEmail('owner@example.com')
            ->setPassword('not-used');

        $this->registryService
            ->method('buildCatalog')
            ->willReturn([$registeredRepository]);

        $this->registryService
            ->method('resolveDefaultRepository')
            ->willReturn($registeredRepository);

        $this->entityManager
            ->expects($this->once())
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
        $this->assertSame('ghp_secret_token', (new GithubTokenCipher('test-app-secret'))->decrypt((string) $user->getGithubTokenEncrypted()));
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
