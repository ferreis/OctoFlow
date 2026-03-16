<?php

namespace App\Tests\Unit\Github;

use App\Entity\User;
use App\Github\Exception\GithubConfigurationException;
use App\Github\GithubProfileService;
use App\Github\GithubTokenCipher;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class GithubProfileServiceTest extends TestCase
{
    private EntityManagerInterface&MockObject $entityManager;
    private GithubProfileService $service;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->service = new GithubProfileService($this->entityManager, new GithubTokenCipher('test-app-secret'));
    }

    public function testUpdateProfileEncryptsTokenAndMarksWorkspaceReady(): void
    {
        $user = (new User())
            ->setEmail('owner@example.com')
            ->setPassword('not-used');

        $this->entityManager
            ->expects($this->once())
            ->method('flush');

        $profile = $this->service->updateProfile($user, [
            'repositoryOwner' => 'acme',
            'repositoryName' => 'delivery-desk',
            'token' => 'ghp_secret_token',
            'clearToken' => false,
        ]);

        $this->assertSame('acme', $profile['repositoryOwner']);
        $this->assertSame('delivery-desk', $profile['repositoryName']);
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

        $this->expectException(GithubConfigurationException::class);
        $this->expectExceptionMessage('Configure the GitHub repository owner and repository name in your profile before using the GitHub workspace.');

        $this->service->buildRuntimeConfiguration($user);
    }
}
