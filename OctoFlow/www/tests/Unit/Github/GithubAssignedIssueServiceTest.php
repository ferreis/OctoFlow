<?php

namespace App\Tests\Unit\Github;

use App\Entity\User;
use App\Github\GithubAssignedIssueService;
use App\Github\GithubGraphQLClientInterface;
use App\Github\GithubIssueCacheService;
use App\Github\GithubProfileService;
use App\Github\GithubRegistryService;
use App\Github\GithubTokenCipher;
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

    protected function setUp(): void
    {
        $this->graphqlClient = $this->createMock(GithubGraphQLClientInterface::class);
        $this->cacheService = $this->createMock(GithubIssueCacheService::class);
        $this->registryService = $this->createMock(GithubRegistryService::class);
        $this->githubAccountRepository = $this->createMock(GithubAccountRepository::class);
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
                $this->stringContains('comments(first: 30)'),
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
                        && isset($issue['repository']['assignableUsers'])
                        && count($issue['repository']['assignableUsers']) === 1
                        && isset($issue['history'])
                        && count($issue['history']) === 4;
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
        );

        $payload = $service->fetchIssue($this->buildTokenOnlyUser(), 'issue-node-1');

        $this->assertSame('github', $payload['source']);
        $this->assertNull($payload['warning']);
        $this->assertCount(4, $payload['history']);
        $this->assertSame('updated', $payload['history'][0]['kind']);
        $this->assertSame('closed', $payload['history'][1]['kind']);
        $this->assertSame('comment', $payload['history'][2]['kind']);
        $this->assertSame('comment-1', $payload['history'][2]['id']);
        $this->assertSame('CLOSED', $payload['item']['state']);
        $this->assertSame('2026-03-13T09:30:00Z', $payload['item']['closedAt']);
        $this->assertCount(1, $payload['item']['repository']['assignableUsers']);
        $this->assertSame('octocat', $payload['item']['repository']['assignableUsers'][0]['login']);
    }

    public function testUpdateIssueCanAddCollaboratorWithoutReplacingCurrentAssignees(): void
    {
        $this->graphqlClient
            ->expects($this->exactly(3))
            ->method('query')
            ->willReturnCallback(function (string $token, string $query, array $variables): array {
                $this->assertSame('ghp_test_token', $token);

                if (str_contains($query, 'updateIssue')) {
                    $this->assertSame([
                        'issueId' => 'issue-node-9',
                        'title' => '[feat] Ajustar automação',
                        'body' => 'Corpo atualizado',
                        'state' => 'OPEN',
                    ], $variables);

                    return [
                        'updateIssue' => [
                            'issue' => [
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
                $this->assertStringContainsString('Atualizacao registrada automaticamente pelo OctoFlow.', $variables['body']);
                $this->assertStringContainsString('Usuario: owner@example.com', $variables['body']);

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
        );

        $result = $service->updateIssue($this->buildTokenOnlyUser(), 'issue-node-9', [
            'title' => '[feat] Ajustar automação',
            'body' => 'Corpo atualizado',
            'state' => 'OPEN',
            'assigneeIds' => ['user-new'],
        ]);

        $this->assertCount(2, $result['assignees']);
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
