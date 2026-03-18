<?php

namespace App\Tests\Unit\Github;

use App\Entity\User;
use App\Github\Exception\GithubGraphQLException;
use App\Github\GithubGraphQLClientInterface;
use App\Github\GithubIssueTemplateCatalog;
use App\Github\GithubProfileService;
use App\Github\GithubRegistryService;
use App\Github\GithubTokenCipher;
use App\Github\GithubWorkspaceService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class GithubWorkspaceServiceTest extends TestCase
{
    private GithubGraphQLClientInterface&MockObject $graphqlClient;
    private GithubIssueTemplateCatalog $templateCatalog;
    private GithubProfileService $profileService;
    private GithubRegistryService&MockObject $registryService;

    protected function setUp(): void
    {
        $this->graphqlClient = $this->createMock(GithubGraphQLClientInterface::class);
        $this->templateCatalog = new GithubIssueTemplateCatalog();
        $this->registryService = $this->createMock(GithubRegistryService::class);
        $this->registryService
            ->method('buildCatalog')
            ->willReturn([$this->buildRegisteredRepository()]);
        $this->registryService
            ->method('resolveDefaultRepository')
            ->willReturn($this->buildRegisteredRepository());

        $this->profileService = new GithubProfileService(
            $this->createMock(EntityManagerInterface::class),
            new GithubTokenCipher('test-app-secret'),
            $this->registryService
        );
    }

    public function testFetchWorkspaceReturnsRepositoryTemplatesAndProjects(): void
    {
        $call = 0;

        $this->graphqlClient
            ->expects($this->exactly(2))
            ->method('query')
            ->willReturnCallback(function (string $token, string $query, array $variables) use (&$call): array {
                ++$call;
                $this->assertSame('ghp_test_token', $token);

                if ($call === 1) {
                    $this->assertSame([
                        'owner' => 'acme',
                        'name' => 'delivery-desk',
                    ], $variables);

                    return [
                        'repository' => [
                            'id' => 'repo-id',
                            'name' => 'delivery-desk',
                            'nameWithOwner' => 'acme/delivery-desk',
                            'description' => 'Workspace do GitHub',
                            'url' => 'https://github.com/acme/delivery-desk',
                            'owner' => [
                                'login' => 'acme',
                            ],
                            'labels' => [
                                'nodes' => [
                                    [
                                        'id' => 'label-1',
                                        'name' => 'bug',
                                        'color' => 'd73a4a',
                                        'description' => 'Erro confirmado',
                                    ],
                                ],
                            ],
                            'assignableUsers' => [
                                'nodes' => [
                                    [
                                        'id' => 'user-1',
                                        'login' => 'ana',
                                        'name' => 'Ana Silva',
                                        'avatarUrl' => 'https://avatars.example/ana',
                                        'url' => 'https://github.com/ana',
                                    ],
                                ],
                            ],
                        ],
                    ];
                }

                $this->assertSame([
                    'login' => 'acme',
                ], $variables);

                return [
                    'organization' => [
                        'projectsV2' => [
                            'nodes' => [
                                [
                                    'id' => 'project-1',
                                    'number' => 4,
                                    'title' => 'Delivery Pipeline',
                                    'shortDescription' => 'Acompanhamento do backlog',
                                    'url' => 'https://github.com/orgs/acme/projects/4',
                                    'closed' => false,
                                    'fields' => [
                                        'nodes' => [
                                            [
                                                '__typename' => 'ProjectV2SingleSelectField',
                                                'id' => 'field-status',
                                                'name' => 'Status',
                                                'options' => [
                                                    [
                                                        'id' => 'option-todo',
                                                        'name' => 'Todo',
                                                        'color' => 'BLUE',
                                                        'description' => 'Fila',
                                                    ],
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'user' => null,
                ];
            });

        $service = new GithubWorkspaceService(
            $this->profileService,
            $this->graphqlClient,
            $this->templateCatalog
        );

        $workspace = $service->fetchWorkspace($this->buildConfiguredUser(), 'acme', 'delivery-desk');

        $this->assertSame('acme/delivery-desk', $workspace['repository']['nameWithOwner']);
        $this->assertCount(1, $workspace['repository']['assignableUsers']);
        $this->assertSame('ana', $workspace['repository']['assignableUsers'][0]['login']);
        $this->assertCount(6, $workspace['templates']);
        $this->assertTrue($workspace['projectsMeta']['available']);
        $this->assertCount(1, $workspace['projects']);
        $this->assertSame('field-status', $workspace['projects'][0]['statusField']['id']);
        $this->assertSame('Todo', $workspace['projects'][0]['statusField']['options'][0]['name']);
        $this->assertTrue($workspace['profile']['workspaceReady']);
    }

    public function testFetchWorkspaceKeepsIssuesFlowAvailableWhenProjectQueryFails(): void
    {
        $call = 0;

        $this->graphqlClient
            ->expects($this->exactly(2))
            ->method('query')
            ->willReturnCallback(function () use (&$call): array {
                ++$call;

                if ($call === 1) {
                    return [
                        'repository' => [
                            'id' => 'repo-id',
                            'name' => 'delivery-desk',
                            'nameWithOwner' => 'acme/delivery-desk',
                            'description' => null,
                            'url' => 'https://github.com/acme/delivery-desk',
                            'owner' => [
                                'login' => 'acme',
                            ],
                            'labels' => [
                                'nodes' => [],
                            ],
                            'assignableUsers' => [
                                'nodes' => [],
                            ],
                        ],
                    ];
                }

                throw new GithubGraphQLException('Projects scope is missing.');
            });

        $service = new GithubWorkspaceService(
            $this->profileService,
            $this->graphqlClient,
            $this->templateCatalog
        );

        $workspace = $service->fetchWorkspace($this->buildConfiguredUser(), 'acme', 'delivery-desk');

        $this->assertSame([], $workspace['projects']);
        $this->assertFalse($workspace['projectsMeta']['available']);
        $this->assertSame('Projects scope is missing.', $workspace['projectsMeta']['message']);
    }

    private function buildConfiguredUser(): User
    {
        $cipher = new GithubTokenCipher('test-app-secret');

        return (new User())
            ->setEmail('owner@example.com')
            ->setPassword('not-used')
            ->setGithubRepositoryOwner('acme')
            ->setGithubTokenEncrypted($cipher->encrypt('ghp_test_token'));
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
