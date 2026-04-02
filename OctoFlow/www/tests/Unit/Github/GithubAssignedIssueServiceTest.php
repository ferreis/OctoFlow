<?php

namespace App\Tests\Unit\Github;

use App\Entity\User;
use App\Github\GithubAssignedIssueService;
use App\Github\Exception\GithubActionForbiddenException;
use App\Github\GithubIssueBodyRenderer;
use App\Github\GithubGraphQLClientInterface;
use App\Github\GithubIssueCacheService;
use App\Github\GithubProfileService;
use App\Github\GithubRegistryService;
use App\Github\GithubIssueTemplateCatalog;
use App\Github\GithubIssueUpdateRenderer;
use App\Github\GithubIssueUpdateTemplateCatalog;
use App\Github\TemplateAccessService;
use App\Github\GithubTokenCipher;
use App\Github\UserCapabilityResolver;
use App\Repository\GithubAccountRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class GithubAssignedIssueServiceTest extends TestCase
{
    private GithubGraphQLClientInterface&MockObject $graphqlClient;
    private GithubIssueCacheService&MockObject $cacheService;
    private GithubProfileService $profileService;
    private GithubRegistryService&MockObject $registryService;
    private GithubAccountRepository&MockObject $githubAccountRepository;
    private GithubIssueTemplateCatalog $templateCatalog;
    private GithubIssueUpdateTemplateCatalog $updateTemplateCatalog;
    private TemplateAccessService $templateAccessService;
    private GithubIssueBodyRenderer $bodyRenderer;
    private GithubIssueUpdateRenderer $updateRenderer;

    protected function setUp(): void
    {
        $this->graphqlClient = $this->createMock(GithubGraphQLClientInterface::class);
        $this->cacheService = $this->createMock(GithubIssueCacheService::class);
        $this->registryService = $this->createMock(GithubRegistryService::class);
        $this->githubAccountRepository = $this->createMock(GithubAccountRepository::class);
        $this->templateCatalog = new GithubIssueTemplateCatalog();
        $this->updateTemplateCatalog = new GithubIssueUpdateTemplateCatalog();
        $this->templateAccessService = new TemplateAccessService(new UserCapabilityResolver());
        $this->bodyRenderer = new GithubIssueBodyRenderer();
        $this->updateRenderer = new GithubIssueUpdateRenderer();
        $this->profileService = new GithubProfileService(
            $this->createMock(EntityManagerInterface::class),
            new GithubTokenCipher('test-app-secret'),
            $this->registryService,
            $this->githubAccountRepository,
        );
    }

    public function testFetchIssuesAggregatesAllRegisteredRepositoriesByDefault(): void
    {
        $call = 0;
        $registeredRepositories = [
            $this->buildRegisteredRepository('acme', 'alpha'),
            $this->buildRegisteredRepository('acme', 'beta'),
        ];

        $this->registryService
            ->expects($this->once())
            ->method('buildCatalog')
            ->with($this->isInstanceOf(User::class), false)
            ->willReturn($registeredRepositories);

        $this->graphqlClient
            ->expects($this->exactly(3))
            ->method('query')
            ->willReturnCallback(function (string $token, string $query, array $variables) use (&$call): array {
                ++$call;
                $this->assertSame('ghp_test_token', $token);

                if ($call === 1) {
                    $this->assertSame([], $variables);

                    return [
                        'viewer' => [
                            'login' => 'octocat',
                        ],
                    ];
                }

                if ($call === 2) {
                    $this->assertSame('acme', $variables['owner']);
                    $this->assertSame('alpha', $variables['name']);
                    $this->assertArrayHasKey('after', $variables);
                    $this->assertNull($variables['after']);

                    return [
                        'repository' => [
                            'id' => 'repo-1',
                            'name' => 'alpha',
                            'nameWithOwner' => 'acme/alpha',
                            'description' => 'Alpha backlog',
                            'url' => 'https://github.com/acme/alpha',
                            'owner' => [
                                'login' => 'acme',
                            ],
                            'issues' => [
                                'nodes' => [
                                    [
                                        'id' => 'issue-1',
                                        'number' => 10,
                                        'title' => '[feat] Melhorar painel alpha',
                                        'body' => 'Conteudo alpha',
                                        'state' => 'OPEN',
                                        'url' => 'https://github.com/acme/alpha/issues/10',
                                        'createdAt' => '2026-03-10T10:00:00Z',
                                        'updatedAt' => '2026-03-11T10:00:00Z',
                                        'viewerCanUpdate' => true,
                                        'viewerCanClose' => true,
                                        'viewerCanReopen' => true,
                                        'author' => [
                                            'login' => 'alice',
                                        ],
                                        'assignees' => [
                                            'nodes' => [],
                                        ],
                                        'labels' => [
                                            'nodes' => [],
                                        ],
                                        'repository' => [
                                            'nameWithOwner' => 'acme/alpha',
                                            'url' => 'https://github.com/acme/alpha',
                                        ],
                                    ],
                                ],
                                'pageInfo' => [
                                    'hasNextPage' => false,
                                    'endCursor' => null,
                                ],
                            ],
                        ],
                    ];
                }

                $this->assertSame('acme', $variables['owner']);
                $this->assertSame('beta', $variables['name']);
                $this->assertArrayHasKey('after', $variables);
                $this->assertNull($variables['after']);

                return [
                    'repository' => [
                        'id' => 'repo-2',
                        'name' => 'beta',
                        'nameWithOwner' => 'acme/beta',
                        'description' => 'Beta backlog',
                        'url' => 'https://github.com/acme/beta',
                        'owner' => [
                            'login' => 'acme',
                        ],
                        'issues' => [
                            'nodes' => [
                                [
                                    'id' => 'issue-2',
                                    'number' => 22,
                                    'title' => '[bug] Corrigir fluxo beta',
                                    'body' => 'Conteudo beta',
                                    'state' => 'OPEN',
                                    'url' => 'https://github.com/acme/beta/issues/22',
                                    'createdAt' => '2026-03-12T10:00:00Z',
                                    'updatedAt' => '2026-03-13T10:00:00Z',
                                    'viewerCanUpdate' => false,
                                    'viewerCanClose' => false,
                                    'viewerCanReopen' => false,
                                    'author' => [
                                        'login' => 'bob',
                                    ],
                                    'assignees' => [
                                        'nodes' => [],
                                    ],
                                    'labels' => [
                                        'nodes' => [],
                                    ],
                                    'repository' => [
                                        'nameWithOwner' => 'acme/beta',
                                        'url' => 'https://github.com/acme/beta',
                                    ],
                                ],
                            ],
                            'pageInfo' => [
                                'hasNextPage' => false,
                                'endCursor' => null,
                            ],
                        ],
                    ],
                ];
            });

        $this->cacheService
            ->method('normalizeScope')
            ->with('all')
            ->willReturn('all');

        $this->cacheService
            ->expects($this->once())
            ->method('syncIssues')
            ->with(
                $this->isInstanceOf(User::class),
                'all',
                $this->callback(static fn (array $items): bool => count($items) === 2),
                'octocat',
                null,
                null,
            );

        $this->cacheService
            ->expects($this->once())
            ->method('buildCachedBoard')
            ->with($this->isInstanceOf(User::class), 'all', null, null)
            ->willReturn([
                'scope' => 'all',
                'repository' => null,
                'repositories' => [
                    ['nameWithOwner' => 'acme/alpha'],
                    ['nameWithOwner' => 'acme/beta'],
                ],
                'items' => [
                    ['repository' => ['nameWithOwner' => 'acme/beta']],
                    ['repository' => ['nameWithOwner' => 'acme/alpha']],
                ],
            ]);

        $service = new GithubAssignedIssueService(
            $this->profileService,
            $this->graphqlClient,
            $this->cacheService,
            $this->registryService,
            $this->templateCatalog,
            $this->updateTemplateCatalog,
            $this->templateAccessService,
            $this->bodyRenderer,
            $this->updateRenderer,
        );

        $issuesBoard = $service->fetchIssues($this->buildTokenOnlyUser());

        $this->assertSame('all', $issuesBoard['scope']);
        $this->assertNull($issuesBoard['repository']);
        $this->assertCount(2, $issuesBoard['repositories']);
        $this->assertCount(2, $issuesBoard['items']);
        $this->assertSame('acme/beta', $issuesBoard['items'][0]['repository']['nameWithOwner']);
        $this->assertSame('acme/alpha', $issuesBoard['items'][1]['repository']['nameWithOwner']);
    }

    public function testFetchIssueReturnsHistoryFromGithubDetail(): void
    {
        $this->graphqlClient
            ->expects($this->once())
            ->method('query')
            ->with(
                'ghp_test_token',
                $this->logicalAnd(
                    $this->stringContains('comments(first: 30)'),
                    $this->stringContains('subIssues(first: 50)'),
                ),
                ['issueId' => 'issue-node-1']
            )
            ->willReturn([
                'viewer' => [
                    'id' => 'viewer-node-1',
                    'login' => 'octocat',
                    'name' => 'Octo Cat',
                    'avatarUrl' => 'https://avatars.example/octocat',
                    'url' => 'https://github.com/octocat',
                ],
                'node' => [
                    '__typename' => 'Issue',
                    'id' => 'issue-node-1',
                    'number' => 14,
                    'title' => '[feat] Nova tela operacional',
                    'body' => "## Contexto\nDetalhes",
                    'state' => 'CLOSED',
                    'url' => 'https://github.com/acme/alpha/issues/14',
                    'createdAt' => '2026-03-10T08:00:00Z',
                    'closedAt' => '2026-03-13T09:30:00Z',
                    'updatedAt' => '2026-03-13T10:15:00Z',
                    'parent' => null,
                    'subIssues' => [
                        'nodes' => [
                            [
                                'id' => 'issue-node-1-child',
                                'number' => 15,
                                'title' => 'Validar rollout da tela',
                                'state' => 'OPEN',
                                'url' => 'https://github.com/acme/alpha/issues/15',
                                'createdAt' => '2026-03-11T08:00:00Z',
                                'updatedAt' => '2026-03-12T10:00:00Z',
                                'assignees' => [
                                    'nodes' => [
                                        [
                                            'id' => 'user-child',
                                            'login' => 'carol',
                                            'name' => 'Carol Souza',
                                            'avatarUrl' => 'https://avatars.example/carol',
                                            'url' => 'https://github.com/carol',
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'viewerCanUpdate' => true,
                    'viewerCanClose' => false,
                    'viewerCanReopen' => true,
                    'author' => [
                        'login' => 'alice',
                    ],
                    'assignees' => [
                        'nodes' => [],
                    ],
                    'labels' => [
                        'nodes' => [],
                    ],
                    'repository' => [
                        'nameWithOwner' => 'acme/alpha',
                        'url' => 'https://github.com/acme/alpha',
                    ],
                    'projectItems' => [
                        'nodes' => [
                            [
                                'project' => [
                                    'id' => 'project-1',
                                    'title' => 'OctoFlow Core',
                                    'url' => 'https://github.com/orgs/acme/projects/1',
                                ],
                            ],
                        ],
                    ],
                    'comments' => [
                        'nodes' => [
                            [
                                'id' => 'comment-1',
                                'body' => "## Atualização\nTudo validado",
                                'createdAt' => '2026-03-12T12:00:00Z',
                                'updatedAt' => '2026-03-12T12:30:00Z',
                                'url' => 'https://github.com/acme/alpha/issues/14#issuecomment-1',
                                'author' => [
                                    'login' => 'bob',
                                ],
                            ],
                        ],
                    ],
                    'timelineItems' => [
                        'nodes' => [
                            [
                                '__typename' => 'SubIssueAddedEvent',
                                'id' => 'subissue-event-1',
                                'createdAt' => '2026-03-12T15:00:00Z',
                                'actor' => [
                                    '__typename' => 'User',
                                    'login' => 'alice',
                                ],
                                'subIssue' => [
                                    'id' => 'issue-node-1-child-2',
                                    'number' => 16,
                                    'title' => 'Publicar documentacao final',
                                    'url' => 'https://github.com/acme/alpha/issues/16',
                                    'createdAt' => '2026-03-12T14:45:00Z',
                                    'author' => [
                                        'login' => 'alice',
                                    ],
                                    'assignees' => [
                                        'nodes' => [],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ]);

        $this->cacheService
            ->expects($this->once())
            ->method('upsertIssue')
            ->with(
                $this->isInstanceOf(User::class),
                $this->callback(function (array $issue): bool {
                    return ($issue['id'] ?? null) === 'issue-node-1'
                        && ($issue['closedAt'] ?? null) === '2026-03-13T09:30:00Z'
                        && isset($issue['subIssues'])
                        && count($issue['subIssues']) === 1
                        && isset($issue['repository']['assignableUsers'])
                        && count($issue['repository']['assignableUsers']) === 1
                        && isset($issue['history'])
                        && count($issue['history']) === 5;
                })
            )
            ->willReturn([
                'id' => 'issue-node-1',
                'number' => 14,
                'title' => '[feat] Nova tela operacional',
                'body' => "## Contexto\nDetalhes",
                'state' => 'CLOSED',
                'url' => 'https://github.com/acme/alpha/issues/14',
                'createdAt' => '2026-03-10T08:00:00Z',
                'updatedAt' => '2026-03-13T10:15:00Z',
                'viewerCanUpdate' => true,
                'viewerCanClose' => false,
                'viewerCanReopen' => true,
                'authorLogin' => 'alice',
                'assignees' => [],
                'labels' => [],
                'repository' => [
                    'nameWithOwner' => 'acme/alpha',
                    'url' => 'https://github.com/acme/alpha',
                    'assignableUsers' => [],
                ],
            ]);

        $service = new GithubAssignedIssueService(
            $this->profileService,
            $this->graphqlClient,
            $this->cacheService,
            $this->registryService,
            $this->templateCatalog,
            $this->updateTemplateCatalog,
            $this->templateAccessService,
            $this->bodyRenderer,
            $this->updateRenderer,
        );

        $payload = $service->fetchIssue($this->buildTokenOnlyUser(), 'issue-node-1');

        $this->assertSame('github', $payload['source']);
        $this->assertNull($payload['warning']);
        $this->assertCount(5, $payload['history']);
        $this->assertSame('updated', $payload['history'][0]['kind']);
        $this->assertSame('closed', $payload['history'][1]['kind']);
        $this->assertSame('comment', $payload['history'][2]['kind']);
        $this->assertSame('Sub-issue criada', $payload['history'][2]['title']);
        $this->assertStringContainsString('Origem: GitHub', $payload['history'][2]['body']);
        $this->assertSame('comment', $payload['history'][3]['kind']);
        $this->assertSame('comment-1', $payload['history'][3]['id']);
        $this->assertSame('CLOSED', $payload['item']['state']);
        $this->assertSame('2026-03-13T09:30:00Z', $payload['item']['closedAt']);
        $this->assertCount(1, $payload['item']['subIssues']);
        $this->assertSame('Validar rollout da tela', $payload['item']['subIssues'][0]['title']);
        $this->assertTrue($payload['item']['hasOpenSubIssues']);
        $this->assertCount(1, $payload['item']['projects']);
        $this->assertSame('OctoFlow Core', $payload['item']['projects'][0]['title']);
        $this->assertCount(1, $payload['item']['repository']['assignableUsers']);
        $this->assertSame('octocat', $payload['item']['repository']['assignableUsers'][0]['login']);
    }

    public function testUpdateIssueBlocksClosingWhenThereAreOpenSubIssues(): void
    {
        $this->graphqlClient
            ->expects($this->once())
            ->method('query')
            ->with(
                'ghp_test_token',
                $this->stringContains('GithubIssueUpdateContext'),
                ['issueId' => 'issue-node-11']
            )
            ->willReturn([
                'node' => [
                    '__typename' => 'Issue',
                    'id' => 'issue-node-11',
                    'number' => 11,
                    'title' => '[feat] Fechar epic operacional',
                    'body' => 'Corpo atual',
                    'state' => 'OPEN',
                    'url' => 'https://github.com/acme/alpha/issues/11',
                    'viewerCanUpdate' => true,
                    'viewerCanClose' => true,
                    'viewerCanReopen' => true,
                    'parent' => null,
                    'subIssues' => [
                        'nodes' => [
                            [
                                'id' => 'issue-node-11-child',
                                'number' => 12,
                                'title' => 'Concluir migracao do relatorio',
                                'state' => 'OPEN',
                                'url' => 'https://github.com/acme/alpha/issues/12',
                                'createdAt' => '2026-03-20T08:00:00Z',
                                'updatedAt' => '2026-03-20T09:00:00Z',
                                'assignees' => [
                                    'nodes' => [],
                                ],
                            ],
                        ],
                    ],
                    'assignees' => [
                        'nodes' => [],
                    ],
                    'repository' => [
                        'id' => 'repo-1',
                        'nameWithOwner' => 'acme/alpha',
                        'url' => 'https://github.com/acme/alpha',
                        'labels' => [
                            'nodes' => [],
                        ],
                        'assignableUsers' => [
                            'nodes' => [],
                        ],
                    ],
                    'projectItems' => [
                        'nodes' => [],
                    ],
                ],
            ]);

        $service = new GithubAssignedIssueService(
            $this->profileService,
            $this->graphqlClient,
            $this->cacheService,
            $this->registryService,
            $this->templateCatalog,
            $this->updateTemplateCatalog,
            $this->templateAccessService,
            $this->bodyRenderer,
            $this->updateRenderer,
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Não é possível concluir a issue principal enquanto existirem sub-issues abertas');

        $service->updateIssue($this->buildTokenOnlyUser(), 'issue-node-11', [
            'title' => '[feat] Fechar epic operacional',
            'state' => 'CLOSED',
        ]);
    }

    public function testUpdateIssueCanAddCollaboratorWithoutReplacingCurrentAssignees(): void
    {
        $this->graphqlClient
            ->expects($this->exactly(4))
            ->method('query')
            ->willReturnCallback(function (string $token, string $query, array $variables): array {
                $this->assertSame('ghp_test_token', $token);

                if (str_contains($query, 'GithubIssueUpdateContext')) {
                    $this->assertSame([
                        'issueId' => 'issue-node-9',
                    ], $variables);

                    return [
                        'node' => [
                            '__typename' => 'Issue',
                            'id' => 'issue-node-9',
                            'body' => "## Contexto atual\nCorpo anterior",
                            'viewerCanUpdate' => true,
                            'viewerCanClose' => true,
                            'viewerCanReopen' => true,
                            'assignees' => [
                                'nodes' => [
                                    [
                                        'id' => 'user-old',
                                        'login' => 'bruno',
                                        'name' => 'Bruno Lima',
                                        'avatarUrl' => 'https://avatars.example/bruno',
                                        'url' => 'https://github.com/bruno',
                                    ],
                                ],
                            ],
                            'repository' => [
                                'nameWithOwner' => 'acme/alpha',
                                'url' => 'https://github.com/acme/alpha',
                                'assignableUsers' => [
                                    'nodes' => [
                                        [
                                            'id' => 'user-old',
                                            'login' => 'bruno',
                                            'name' => 'Bruno Lima',
                                            'avatarUrl' => 'https://avatars.example/bruno',
                                            'url' => 'https://github.com/bruno',
                                        ],
                                        [
                                            'id' => 'user-new',
                                            'login' => 'ana',
                                            'name' => 'Ana Silva',
                                            'avatarUrl' => 'https://avatars.example/ana',
                                            'url' => 'https://github.com/ana',
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ];
                }

                if (str_contains($query, 'updateIssue')) {
                    $this->assertSame('issue-node-9', $variables['issueId']);
                    $this->assertSame('[feat] Ajustar automação', $variables['title']);
                    $this->assertSame('OPEN', $variables['state']);
                    $this->assertStringContainsString("## Contexto atual\nCorpo anterior", $variables['body']);
                    $this->assertStringContainsString('## Atualização de status', $variables['body']);
                    $this->assertStringContainsString('- Responsável: Ana Silva (ana)', $variables['body']);
                    $this->assertStringContainsString('- Commit relacionado: [abc1234](https://github.com/acme/alpha/commit/abc1234)', $variables['body']);
                    $this->assertStringContainsString("### Situação atual\nCorpo atualizado", $variables['body']);
                    $this->assertStringContainsString('Observacao complementar', $variables['body']);

                    return [
                        'updateIssue' => [
                            'issue' => [
                                'id' => 'issue-node-9',
                                'number' => 9,
                                'title' => '[feat] Ajustar automação',
                                'body' => "## Contexto atual\nCorpo anterior\n\n## Atualização de status",
                                'state' => 'OPEN',
                                'url' => 'https://github.com/acme/alpha/issues/9',
                                'createdAt' => '2026-03-15T08:00:00Z',
                                'updatedAt' => '2026-03-18T09:00:00Z',
                                'viewerCanUpdate' => true,
                                'viewerCanClose' => true,
                                'viewerCanReopen' => true,
                                'author' => [
                                    'login' => 'alice',
                                ],
                                'assignees' => [
                                    'nodes' => [
                                        [
                                            'id' => 'user-old',
                                            'login' => 'bruno',
                                            'name' => 'Bruno Lima',
                                            'avatarUrl' => 'https://avatars.example/bruno',
                                            'url' => 'https://github.com/bruno',
                                        ],
                                    ],
                                ],
                                'labels' => [
                                    'nodes' => [],
                                ],
                                'repository' => [
                                    'nameWithOwner' => 'acme/alpha',
                                    'url' => 'https://github.com/acme/alpha',
                                ],
                            ],
                        ],
                    ];
                }

                if (str_contains($query, 'addAssigneesToAssignable')) {
                    $this->assertSame('issue-node-9', $variables['issueId']);
                    $this->assertSame(['user-new'], $variables['assigneeIds']);

                    return [
                        'addAssigneesToAssignable' => [
                            'assignable' => [
                                'id' => 'issue-node-9',
                                'number' => 9,
                                'title' => '[feat] Ajustar automação',
                                'body' => 'Corpo atualizado',
                                'state' => 'OPEN',
                                'url' => 'https://github.com/acme/alpha/issues/9',
                                'createdAt' => '2026-03-15T08:00:00Z',
                                'updatedAt' => '2026-03-18T09:00:00Z',
                                'viewerCanUpdate' => true,
                                'viewerCanClose' => true,
                                'viewerCanReopen' => true,
                                'author' => [
                                    'login' => 'alice',
                                ],
                                'assignees' => [
                                    'nodes' => [
                                        [
                                            'id' => 'user-old',
                                            'login' => 'bruno',
                                            'name' => 'Bruno Lima',
                                            'avatarUrl' => 'https://avatars.example/bruno',
                                            'url' => 'https://github.com/bruno',
                                        ],
                                        [
                                            'id' => 'user-new',
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
                                    'nameWithOwner' => 'acme/alpha',
                                    'url' => 'https://github.com/acme/alpha',
                                ],
                            ],
                        ],
                    ];
                }

                $this->assertStringContainsString('addComment', $query);
                $this->assertSame('issue-node-9', $variables['issueId']);
                $this->assertStringContainsString('Atualização registrada automaticamente pelo OctoFlow.', $variables['body']);
                $this->assertStringContainsString('Usuário: owner@example.com', $variables['body']);

                return [
                    'addComment' => [
                        'commentEdge' => [
                            'node' => [
                                'id' => 'comment-node-1',
                            ],
                        ],
                    ],
                ];
            });

        $this->cacheService
            ->expects($this->once())
            ->method('upsertIssue')
            ->with(
                $this->isInstanceOf(User::class),
                $this->callback(function (array $issue): bool {
                    $this->assertCount(2, $issue['assignees']);
                    $this->assertSame('bruno', $issue['assignees'][0]['login']);
                    $this->assertSame('ana', $issue['assignees'][1]['login']);

                    return true;
                })
            )
            ->willReturn([
                'id' => 'issue-node-9',
                'assignees' => [
                    ['id' => 'user-old', 'login' => 'bruno'],
                    ['id' => 'user-new', 'login' => 'ana'],
                ],
            ]);

        $service = new GithubAssignedIssueService(
            $this->profileService,
            $this->graphqlClient,
            $this->cacheService,
            $this->registryService,
            $this->templateCatalog,
            $this->updateTemplateCatalog,
            $this->templateAccessService,
            $this->bodyRenderer,
            $this->updateRenderer,
        );

        $result = $service->updateIssue($this->buildTokenOnlyUser(), 'issue-node-9', [
            'title' => '[feat] Ajustar automação',
            'state' => 'OPEN',
            'templateKey' => 'status-update',
            'templateFields' => [
                'owner' => 'user-new',
                'commitRef' => 'abc1234',
                'currentStatus' => 'Corpo atualizado',
                'nextStep' => 'Validar com atendimento',
                'notes' => 'Notas finais',
            ],
            'additionalNotes' => 'Observacao complementar',
            'assigneeIds' => ['user-new'],
        ]);

        $this->assertCount(2, $result['assignees']);
    }

    public function testUpdateIssueThrowsForbiddenWhenViewerCannotUpdateIssue(): void
    {
        $this->graphqlClient
            ->expects($this->once())
            ->method('query')
            ->with(
                'ghp_test_token',
                $this->stringContains('GithubIssueUpdateContext'),
                ['issueId' => 'issue-node-77']
            )
            ->willReturn([
                'node' => [
                    '__typename' => 'Issue',
                    'id' => 'issue-node-77',
                    'number' => 77,
                    'title' => '[feat] Ajustar regra de acesso',
                    'body' => 'Corpo inicial',
                    'state' => 'OPEN',
                    'url' => 'https://github.com/acme/alpha/issues/77',
                    'viewerCanUpdate' => false,
                    'viewerCanClose' => false,
                    'viewerCanReopen' => false,
                    'parent' => null,
                    'subIssues' => [
                        'nodes' => [],
                    ],
                    'assignees' => [
                        'nodes' => [],
                    ],
                    'repository' => [
                        'id' => 'repo-1',
                        'nameWithOwner' => 'acme/alpha',
                        'url' => 'https://github.com/acme/alpha',
                        'labels' => [
                            'nodes' => [],
                        ],
                        'assignableUsers' => [
                            'nodes' => [],
                        ],
                    ],
                    'projectItems' => [
                        'nodes' => [],
                    ],
                ],
            ]);

        $service = new GithubAssignedIssueService(
            $this->profileService,
            $this->graphqlClient,
            $this->cacheService,
            $this->registryService,
            $this->templateCatalog,
            $this->updateTemplateCatalog,
            $this->templateAccessService,
            $this->bodyRenderer,
            $this->updateRenderer,
        );

        $this->expectException(GithubActionForbiddenException::class);
        $this->expectExceptionMessage('You do not have permission to update this GitHub issue.');

        $service->updateIssue($this->buildTokenOnlyUser(), 'issue-node-77', [
            'title' => '[feat] Ajustar regra de acesso',
            'state' => 'OPEN',
        ]);
    }

    private function buildTokenOnlyUser(): User
    {
        $cipher = new GithubTokenCipher('test-app-secret');

        return (new User())
            ->setEmail('owner@example.com')
            ->setPassword('not-used')
            ->setGithubTokenEncrypted($cipher->encrypt('ghp_test_token'));
    }

    /**
     * @return array<string, mixed>
     */
    private function buildRegisteredRepository(string $ownerLogin, string $name): array
    {
        return [
            'id' => sprintf('%s-%s', $ownerLogin, $name),
            'ownerLogin' => $ownerLogin,
            'name' => $name,
            'nameWithOwner' => sprintf('%s/%s', $ownerLogin, $name),
            'url' => sprintf('https://github.com/%s/%s', $ownerLogin, $name),
            'isIgnored' => false,
        ];
    }
}
