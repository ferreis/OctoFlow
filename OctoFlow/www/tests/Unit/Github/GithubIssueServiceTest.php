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
                    $this->assertStringContainsString('## Criterios de aceitação', $variables['body']);
                    $this->assertStringContainsString('Sincronizar backlog automaticamente', $variables['body']);

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
                'description' => 'Hoje o backlog vive fora da aplicação.',
                'businessRule' => 'A funcionalidade precisa respeitar o fluxo de atendimento e priorização do time.',
                'acceptanceCriteria' => "Criar issue no repo correto\nSincronizar backlog automaticamente",
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

    public function testCreateIssueCanAssignCollaboratorAfterCreation(): void
    {
        $call = 0;

        $this->cacheService
            ->expects($this->once())
            ->method('upsertIssue')
            ->with(
                $this->isInstanceOf(User::class),
                $this->callback(function (array $issue): bool {
                    $this->assertCount(1, $issue['assignees']);
                    $this->assertSame('user-node-1', $issue['assignees'][0]['id']);
                    $this->assertSame('ana', $issue['assignees'][0]['login']);

                    return true;
                })
            )
            ->willReturn([
                'id' => 'issue-node-id',
                'number' => 51,
                'title' => '[bug] Corrigir fila de sync',
                'body' => 'Body salvo no cache',
                'state' => 'OPEN',
                'url' => 'https://github.com/acme/delivery-desk/issues/51',
                'createdAt' => '2026-03-18T12:00:00Z',
                'updatedAt' => '2026-03-18T12:00:00Z',
                'assignees' => [
                    [
                        'id' => 'user-node-1',
                        'login' => 'ana',
                        'name' => 'Ana Silva',
                        'avatarUrl' => 'https://avatars.example/ana',
                        'url' => 'https://github.com/ana',
                    ],
                ],
                'repository' => [
                    'nameWithOwner' => 'acme/delivery-desk',
                    'url' => 'https://github.com/acme/delivery-desk',
                ],
            ]);

        $this->graphqlClient
            ->expects($this->exactly(3))
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
                            'assignableUsers' => [
                                'nodes' => [
                                    [
                                        'id' => 'user-node-1',
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

                if ($call === 2) {
                    $this->assertStringContainsString('createIssue', $query);
                    $this->assertSame('repo-id', $variables['repositoryId']);

                    return [
                        'createIssue' => [
                            'issue' => [
                                'id' => 'issue-node-id',
                                'number' => 51,
                                'title' => '[bug] Corrigir fila de sync',
                                'body' => '## Problema ou oportunidade',
                                'state' => 'OPEN',
                                'url' => 'https://github.com/acme/delivery-desk/issues/51',
                                'createdAt' => '2026-03-18T12:00:00Z',
                                'updatedAt' => '2026-03-18T12:00:00Z',
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

                $this->assertStringContainsString('addAssigneesToAssignable', $query);
                $this->assertSame('issue-node-id', $variables['issueId']);
                $this->assertSame(['user-node-1'], $variables['assigneeIds']);

                return [
                    'addAssigneesToAssignable' => [
                        'assignable' => [
                            'id' => 'issue-node-id',
                            'number' => 51,
                            'title' => '[bug] Corrigir fila de sync',
                            'body' => '## Problema ou oportunidade',
                            'state' => 'OPEN',
                            'url' => 'https://github.com/acme/delivery-desk/issues/51',
                            'createdAt' => '2026-03-18T12:00:00Z',
                            'updatedAt' => '2026-03-18T12:00:00Z',
                            'viewerCanUpdate' => true,
                            'viewerCanClose' => true,
                            'viewerCanReopen' => false,
                            'author' => [
                                'login' => 'owner',
                            ],
                            'assignees' => [
                                'nodes' => [
                                    [
                                        'id' => 'user-node-1',
                                        'login' => 'ana',
                                        'name' => 'Ana Silva',
                                        'avatarUrl' => 'https://avatars.example/ana',
                                        'url' => 'https://github.com/ana',
                                    ],
                                ],
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
            'title' => 'Corrigir fila de sync',
            'repositoryOwner' => 'acme',
            'repositoryName' => 'delivery-desk',
            'fields' => [
                'description' => 'A fila trava depois do retry.',
                'businessRule' => 'O fluxo de reprocessamento deve manter a fila operacional durante novas tentativas.',
                'acceptanceCriteria' => "Registrar retries sem congelar a fila\nPermitir nova execução automatica",
            ],
            'assigneeIds' => ['user-node-1'],
        ]);

        $this->assertSame('issue-node-id', $result['item']['id']);
        $this->assertCount(1, $result['item']['assignees']);
        $this->assertSame('ana', $result['item']['assignees'][0]['login']);
    }

    public function testCreateIssueCanCreateMissingLabelsBeforeCreation(): void
    {
        $call = 0;

        $this->cacheService
            ->expects($this->once())
            ->method('upsertIssue')
            ->with(
                $this->isInstanceOf(User::class),
                $this->callback(function (array $issue): bool {
                    $this->assertCount(2, $issue['labels']);
                    $this->assertSame('Priority', $issue['labels'][0]['name']);
                    $this->assertSame('Nova tag', $issue['labels'][1]['name']);

                    return true;
                })
            )
            ->willReturn([
                'id' => 'issue-node-id',
                'number' => 77,
                'title' => '[feat] Melhorar busca por tags',
                'body' => 'Body salvo no cache',
                'state' => 'OPEN',
                'url' => 'https://github.com/acme/delivery-desk/issues/77',
                'createdAt' => '2026-03-18T16:00:00Z',
                'updatedAt' => '2026-03-18T16:00:00Z',
                'labels' => [
                    [
                        'id' => 'label-priority',
                        'name' => 'Priority',
                        'color' => 'D73A4A',
                        'description' => null,
                    ],
                    [
                        'id' => 'label-nova-tag',
                        'name' => 'Nova tag',
                        'color' => '0EA5E9',
                        'description' => null,
                    ],
                ],
                'repository' => [
                    'nameWithOwner' => 'acme/delivery-desk',
                    'url' => 'https://github.com/acme/delivery-desk',
                ],
            ]);

        $this->graphqlClient
            ->expects($this->exactly(3))
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
                                'nodes' => [
                                    [
                                        'id' => 'label-priority',
                                        'name' => 'Priority',
                                        'color' => 'D73A4A',
                                        'description' => null,
                                    ],
                                ],
                            ],
                        ],
                    ];
                }

                if ($call === 2) {
                    $this->assertStringContainsString('createLabel', $query);
                    $this->assertSame('repo-id', $variables['repositoryId']);
                    $this->assertSame('Nova tag', $variables['name']);
                    $this->assertSame('0EA5E9', $variables['color']);

                    return [
                        'createLabel' => [
                            'label' => [
                                'id' => 'label-nova-tag',
                                'name' => 'Nova tag',
                                'color' => '0EA5E9',
                                'description' => null,
                            ],
                        ],
                    ];
                }

                $this->assertStringContainsString('createIssue', $query);
                $this->assertSame('repo-id', $variables['repositoryId']);
                $this->assertSame('[feat] Melhorar busca por tags', $variables['title']);
                $this->assertSame(['label-priority', 'label-nova-tag'], $variables['labelIds']);
                $this->assertStringContainsString('## Descri', $variables['body']);

                return [
                    'createIssue' => [
                        'issue' => [
                            'id' => 'issue-node-id',
                            'number' => 77,
                            'title' => '[feat] Melhorar busca por tags',
                            'body' => '## Problema ou oportunidade',
                            'state' => 'OPEN',
                            'url' => 'https://github.com/acme/delivery-desk/issues/77',
                            'createdAt' => '2026-03-18T16:00:00Z',
                            'updatedAt' => '2026-03-18T16:00:00Z',
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
                                'nodes' => [
                                    [
                                        'id' => 'label-priority',
                                        'name' => 'Priority',
                                        'color' => 'D73A4A',
                                        'description' => null,
                                    ],
                                    [
                                        'id' => 'label-nova-tag',
                                        'name' => 'Nova tag',
                                        'color' => '0EA5E9',
                                        'description' => null,
                                    ],
                                ],
                            ],
                            'repository' => [
                                'nameWithOwner' => 'acme/delivery-desk',
                                'url' => 'https://github.com/acme/delivery-desk',
                            ],
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
            'title' => 'Melhorar busca por tags',
            'repositoryOwner' => 'acme',
            'repositoryName' => 'delivery-desk',
            'fields' => [
                'description' => 'O formulario precisa permitir criar tags sem sair do campo.',
                'businessRule' => 'Tags duplicadas devem ser evitadas mesmo com diferenca apenas de maiusculas e minusculas.',
                'acceptanceCriteria' => "Selecionar tags existentes\nCriar tags novas no envio",
            ],
            'newLabelNames' => [' priority ', ' Nova tag ', 'nova TAG', '   '],
        ]);

        $this->assertSame(77, $result['issue']['number']);
        $this->assertCount(2, $result['item']['labels']);
        $this->assertSame('Priority', $result['item']['labels'][0]['name']);
        $this->assertSame('Nova tag', $result['item']['labels'][1]['name']);
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
