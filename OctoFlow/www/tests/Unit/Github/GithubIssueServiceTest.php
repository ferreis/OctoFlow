<?php

namespace App\Tests\Unit\Github;

use App\Entity\User;
use App\Github\GithubGraphQLClientInterface;
use App\Github\GithubIssueBodyRenderer;
use App\Github\GithubIssueCacheService;
use App\Github\GithubIssueService;
use App\Github\GithubIssueTemplateCatalog;
use App\Github\GithubProfileService;
use App\Github\GithubRegistryService;
use App\Github\GithubTokenCipher;
use App\Github\GithubWorkspaceService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class GithubIssueServiceTest extends TestCase
{
    private GithubGraphQLClientInterface&MockObject $graphqlClient;
    private GithubIssueTemplateCatalog $templateCatalog;
    private GithubIssueBodyRenderer $renderer;
    private GithubProfileService $profileService;
    private GithubIssueCacheService&MockObject $cacheService;
    private GithubRegistryService&MockObject $registryService;

    protected function setUp(): void
    {
        $this->graphqlClient = $this->createMock(GithubGraphQLClientInterface::class);
        $this->templateCatalog = new GithubIssueTemplateCatalog();
        $this->renderer = new GithubIssueBodyRenderer();
        $this->cacheService = $this->createMock(GithubIssueCacheService::class);
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

    public function testCreateIssueCanAttachProjectAndSetInitialStatus(): void
    {
        $call = 0;

        $this->cacheService
            ->expects($this->once())
            ->method('upsertIssue')
            ->with(
                $this->isInstanceOf(User::class),
                $this->callback(function (array $issue): bool {
                    $this->assertSame('issue-node-id', $issue['id']);
                    $this->assertSame('OPEN', $issue['state']);
                    $this->assertSame('acme/delivery-desk', $issue['repository']['nameWithOwner']);

                    return true;
                })
            )
            ->willReturn([
                'id' => 'issue-node-id',
                'number' => 42,
                'title' => '[feat] Entregar workspace GitHub',
                'body' => 'Body salvo no cache',
                'state' => 'OPEN',
                'url' => 'https://github.com/acme/delivery-desk/issues/42',
                'createdAt' => '2026-03-16T12:00:00Z',
                'updatedAt' => '2026-03-16T12:00:00Z',
                'repository' => [
                    'nameWithOwner' => 'acme/delivery-desk',
                    'url' => 'https://github.com/acme/delivery-desk',
                ],
            ]);

        $this->graphqlClient
            ->expects($this->exactly(5))
            ->method('query')
            ->willReturnCallback(function (string $token, string $query, array $variables) use (&$call): array {
                ++$call;
                $this->assertSame('ghp_test_token', $token);

                if ($call === 1) {
                    return [
                        'repository' => [
                            'id' => 'repo-id',
                            'name' => 'delivery-desk',
                            'nameWithOwner' => 'acme/delivery-desk',
                            'description' => 'Workspace',
                            'url' => 'https://github.com/acme/delivery-desk',
                            'owner' => [
                                'login' => 'acme',
                            ],
                            'labels' => [
                                'nodes' => [],
                            ],
                        ],
                    ];
                }

                if ($call === 2) {
                    return [
                        'organization' => [
                            'projectsV2' => [
                                'nodes' => [
                                    [
                                        'id' => 'project-1',
                                        'number' => 8,
                                        'title' => 'Roadmap',
                                        'shortDescription' => null,
                                        'url' => 'https://github.com/orgs/acme/projects/8',
                                        'closed' => false,
                                        'fields' => [
                                            'nodes' => [
                                                [
                                                    '__typename' => 'ProjectV2SingleSelectField',
                                                    'id' => 'field-status',
                                                    'name' => 'Status',
                                                    'options' => [
                                                        [
                                                            'id' => 'status-backlog',
                                                            'name' => 'Backlog',
                                                            'color' => 'BLUE',
                                                            'description' => null,
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
                }

                if ($call === 3) {
                    $this->assertStringContainsString('createIssue', $query);
                    $this->assertSame('repo-id', $variables['repositoryId']);
                    $this->assertSame('[feat] Entregar workspace GitHub', $variables['title']);
                    $this->assertSame(['label-enhancement'], $variables['labelIds']);
                    $this->assertStringContainsString('## Criterios de aceite', $variables['body']);
                    $this->assertStringContainsString('- [ ] Sincronizar Project automaticamente', $variables['body']);

                    return [
                        'createIssue' => [
                            'issue' => [
                                'id' => 'issue-node-id',
                                'number' => 42,
                                'title' => '[feat] Entregar workspace GitHub',
                                'body' => '## Problema ou oportunidade',
                                'state' => 'OPEN',
                                'url' => 'https://github.com/acme/delivery-desk/issues/42',
                                'createdAt' => '2026-03-16T12:00:00Z',
                                'updatedAt' => '2026-03-16T12:00:00Z',
                                'viewerCanUpdate' => true,
                                'viewerCanClose' => true,
                                'viewerCanReopen' => false,
                                'author' => [
                                    'login' => 'owner',
                                ],
                                'assignees' => [
                                    'nodes' => [],
                                ],
                                'labels' => [
                                    'nodes' => [],
                                ],
                                'repository' => [
                                    'nameWithOwner' => 'acme/delivery-desk',
                                    'url' => 'https://github.com/acme/delivery-desk',
                                ],
                            ],
                        ],
                    ];
                }

                if ($call === 4) {
                    $this->assertStringContainsString('addProjectV2ItemById', $query);
                    $this->assertSame('project-1', $variables['projectId']);
                    $this->assertSame('issue-node-id', $variables['contentId']);

                    return [
                        'addProjectV2ItemById' => [
                            'item' => [
                                'id' => 'project-item-id',
                            ],
                        ],
                    ];
                }

                $this->assertStringContainsString('updateProjectV2ItemFieldValue', $query);
                $this->assertSame('project-1', $variables['projectId']);
                $this->assertSame('project-item-id', $variables['itemId']);
                $this->assertSame('field-status', $variables['fieldId']);
                $this->assertSame('status-backlog', $variables['statusOptionId']);

                return [
                    'updateProjectV2ItemFieldValue' => [
                        'projectV2Item' => [
                            'id' => 'project-item-id',
                        ],
                    ],
                ];
            });

        $workspaceService = new GithubWorkspaceService(
            $this->profileService,
            $this->graphqlClient,
            $this->templateCatalog
        );

        $service = new GithubIssueService(
            $workspaceService,
            $this->profileService,
            $this->graphqlClient,
            $this->templateCatalog,
            $this->renderer,
            $this->cacheService
        );

        $result = $service->createIssue($this->buildConfiguredUser(), [
            'template' => 'feature-request',
            'title' => 'Entregar workspace GitHub',
            'repositoryOwner' => 'acme',
            'repositoryName' => 'delivery-desk',
            'fields' => [
                'problem' => 'Hoje o backlog vive fora da aplicacao.',
                'proposal' => 'Criar um painel com templates e preview.',
                'userImpact' => 'Menos retrabalho para o time.',
                'acceptanceCriteria' => [
                    'Criar issue no repo correto',
                    'Sincronizar Project automaticamente',
                ],
            ],
            'labelIds' => ['label-enhancement'],
            'projectId' => 'project-1',
            'statusOptionId' => 'status-backlog',
        ]);

        $this->assertSame(42, $result['issue']['number']);
        $this->assertSame('issue-node-id', $result['item']['id']);
        $this->assertTrue($result['project']['attached']);
        $this->assertTrue($result['project']['statusUpdated']);
        $this->assertNull($result['project']['message']);
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
