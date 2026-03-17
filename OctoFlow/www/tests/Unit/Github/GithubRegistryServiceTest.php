<?php

namespace App\Tests\Unit\Github;

use App\Entity\Github;
use App\Entity\User;
use App\Github\GithubRegistryService;
use App\Repository\GithubRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class GithubRegistryServiceTest extends TestCase
{
    private EntityManagerInterface&MockObject $entityManager;
    private GithubRepository&MockObject $githubRepository;
    private GithubRegistryService $service;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->githubRepository = $this->createMock(GithubRepository::class);
        $this->service = new GithubRegistryService(
            $this->entityManager,
            $this->githubRepository
        );
    }

    public function testCreateRepositoryUsesOwnerAndNameFromGithubUrl(): void
    {
        $user = (new User())
            ->setEmail('owner@example.com')
            ->setPassword('not-used');

        $this->githubRepository
            ->expects($this->once())
            ->method('findOneByOwnerAndRepository')
            ->with($user, 'tiago-zis', 'docker_mapi')
            ->willReturn(null);

        $this->entityManager
            ->expects($this->once())
            ->method('persist');

        $this->entityManager
            ->expects($this->once())
            ->method('flush');

        $repository = $this->service->createRepository($user, [
            'ownerLogin' => 'ferreis',
            'name' => 'docker_mapi',
            'url' => 'https://github.com/tiago-zis/docker_mapi',
            'isIgnored' => false,
        ]);

        $this->assertSame('tiago-zis', $repository->getOwnerLogin());
        $this->assertSame('docker_mapi', $repository->getName());
        $this->assertSame('tiago-zis/docker_mapi', $repository->getNameWithOwner());
        $this->assertSame('https://github.com/tiago-zis/docker_mapi', $repository->getUrl());
    }

    public function testBuildPayloadNormalizesExistingRepositoryUsingGithubUrl(): void
    {
        $repository = (new Github())
            ->setOwnerLogin('ferreis')
            ->setName('docker_mapi')
            ->setUrl('https://github.com/tiago-zis/docker_mapi');

        $payload = $this->service->buildPayload($repository);

        $this->assertSame('tiago-zis', $payload['ownerLogin']);
        $this->assertSame('docker_mapi', $payload['name']);
        $this->assertSame('tiago-zis/docker_mapi', $payload['nameWithOwner']);
        $this->assertSame('https://github.com/tiago-zis/docker_mapi', $payload['url']);
    }
}
