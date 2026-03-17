<?php

namespace App\Tests\Unit\Github;

use App\Entity\User;
use App\Github\GithubAssignedIssueService;
use App\Github\GithubIssueCacheService;
use App\Github\GithubGraphQLClientInterface;
use App\Github\GithubProfileService;
use App\Github\GithubTokenCipher;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class GithubAssignedIssueServiceTest extends TestCase
{
    private GithubGraphQLClientInterface&MockObject $graphqlClient;
    private GithubIssueCacheService&MockObject $cacheService;
    private GithubProfileService $profileService;

    protected function setUp(): void
    {
        $this->graphqlClient = $this->createMock(GithubGraphQLClientInterface::class);
        $this->cacheService = $this->createMock(GithubIssueCacheService::class);
        $this->profileService = new GithubProfileService(
            $this->createMock(EntityManagerInterface::class),
            new GithubTokenCipher('test-app-secret')
        );
    }

    public function testFetchIssuesAggregatesAllRepositoriesByDefault(): void
    {
        $call = 0;

        $this->graphqlClient
            ->expects($this->exactly(3))
            ->method('query')
            ->willReturnCallback(function (string $token, string $query, array $variables) use (&$call): array {
                ++$call;
                $this->assertSame('ghp_test_token', $token);

                if ($call === 1) {
                    $this->assertArrayHasKey('after', $variables);
                    $this->assertNull($variables['after']);

                    return [
                        'viewer' => [
                            'login' => 'octocat',
                            'repositories' => [
                                'nodes' => [
                                    [
                                        'id' => 'repo-1',
                                        'name' => 'alpha',
                                        'nameWithOwner' => 'acme/alpha',
                                        'description' => 'Alpha backlog',
                                        'url' => 'https://github.com/acme/alpha',
                                        'isPrivate' => false,
                                        'owner' => [
                                            'login' => 'acme',
                                        ],
                                    ],
                                    [
                                        'id' => 'repo-2',
                                        'name' => 'beta',
                                        'nameWithOwner' => 'acme/beta',
                                        'description' => 'Beta backlog',
                                        'url' => 'https://github.com/acme/beta',
                                        'isPrivate' => true,
                                        'owner' => [
                                            'login' => 'acme',
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

                if ($call === 2) {
                    $this->assertSame('acme', $variables['owner']);
                    $this->assertSame('alpha', $variables['name']);

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
                $this->callback(static fn (array $repositories): bool => count($repositories) === 2),
                null,
                null,
            );

        $this->cacheService
            ->expects($this->once())
            ->method('buildCachedBoard')
            ->with($this->isInstanceOf(User::class), 'all', null, null)
            ->willReturn([
                'scope' => 'all',
                'viewer' => [
                    'login' => 'octocat',
                ],
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
            $this->cacheService
        );

        $issuesBoard = $service->fetchIssues($this->buildTokenOnlyUser());

        $this->assertSame('all', $issuesBoard['scope']);
        $this->assertSame('octocat', $issuesBoard['viewer']['login']);
        $this->assertNull($issuesBoard['repository']);
        $this->assertCount(2, $issuesBoard['repositories']);
        $this->assertCount(2, $issuesBoard['items']);
        $this->assertSame('acme/beta', $issuesBoard['items'][0]['repository']['nameWithOwner']);
        $this->assertSame('acme/alpha', $issuesBoard['items'][1]['repository']['nameWithOwner']);
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
