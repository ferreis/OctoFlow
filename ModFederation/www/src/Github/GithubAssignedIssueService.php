<?php

namespace App\Github;

use App\Entity\User;
use App\Github\Exception\GithubGraphQLException;

final class GithubAssignedIssueService
{
    private const ISSUE_SCOPE_ALL = 'all';
    private const ISSUE_SCOPE_ASSIGNED = 'assigned';
    private const ISSUE_SCOPE_REPOSITORY = 'repository';

    private const VIEWER_LOGIN_QUERY = <<<'GRAPHQL'
query GithubViewerLogin {
  viewer {
    login
  }
}
GRAPHQL;

    private const ISSUE_DETAIL_QUERY = <<<'GRAPHQL'
query GithubIssueDetail($issueId: ID!) {
  node(id: $issueId) {
    __typename
    ... on Issue {
      id
      number
      title
      body
      state
      url
      createdAt
      updatedAt
      viewerCanUpdate
      viewerCanClose
      viewerCanReopen
      author {
        login
      }
      assignees(first: 10) {
        nodes {
          login
          name
          avatarUrl
          url
        }
      }
      labels(first: 15) {
        nodes {
          id
          name
          color
          description
        }
      }
      repository {
        nameWithOwner
        url
      }
    }
  }
}
GRAPHQL;

    private const REPOSITORY_ASSIGNED_ISSUES_QUERY = <<<'GRAPHQL'
query GithubRepositoryAssignedIssues($owner: String!, $name: String!, $assignee: String!, $after: String) {
  repository(owner: $owner, name: $name) {
    id
    name
    nameWithOwner
    description
    url
    owner {
      login
    }
    issues(
      first: 100
      after: $after
      states: [OPEN, CLOSED]
      filterBy: {assignee: $assignee}
      orderBy: {field: UPDATED_AT, direction: DESC}
    ) {
      nodes {
        id
        number
        title
        body
        state
        url
        createdAt
        updatedAt
        viewerCanUpdate
        viewerCanClose
        viewerCanReopen
        author {
          login
        }
        assignees(first: 10) {
          nodes {
            login
            name
            avatarUrl
            url
          }
        }
        labels(first: 15) {
          nodes {
            id
            name
            color
            description
          }
        }
        repository {
          nameWithOwner
          url
        }
      }
      pageInfo {
        hasNextPage
        endCursor
      }
    }
  }
}
GRAPHQL;

    private const REPOSITORY_ALL_ISSUES_QUERY = <<<'GRAPHQL'
query GithubRepositoryIssues($owner: String!, $name: String!, $after: String) {
  repository(owner: $owner, name: $name) {
    id
    name
    nameWithOwner
    description
    url
    owner {
      login
    }
    issues(
      first: 100
      after: $after
      states: [OPEN, CLOSED]
      orderBy: {field: UPDATED_AT, direction: DESC}
    ) {
      nodes {
        id
        number
        title
        body
        state
        url
        createdAt
        updatedAt
        viewerCanUpdate
        viewerCanClose
        viewerCanReopen
        author {
          login
        }
        assignees(first: 10) {
          nodes {
            login
            name
            avatarUrl
            url
          }
        }
        labels(first: 15) {
          nodes {
            id
            name
            color
            description
          }
        }
        repository {
          nameWithOwner
          url
        }
      }
      pageInfo {
        hasNextPage
        endCursor
      }
    }
  }
}
GRAPHQL;

    private const UPDATE_ISSUE_MUTATION = <<<'GRAPHQL'
mutation GithubUpdateIssue($issueId: ID!, $title: String!, $body: String!, $state: IssueState!) {
  updateIssue(input: {id: $issueId, title: $title, body: $body, state: $state}) {
    issue {
      id
      number
      title
      body
      state
      url
      createdAt
      updatedAt
      viewerCanUpdate
      viewerCanClose
      viewerCanReopen
      author {
        login
      }
      assignees(first: 10) {
        nodes {
          login
          name
          avatarUrl
          url
        }
      }
      labels(first: 15) {
        nodes {
          id
          name
          color
          description
        }
      }
      repository {
        nameWithOwner
        url
      }
    }
  }
}
GRAPHQL;

    public function __construct(
        private readonly GithubProfileService $profileService,
        private readonly GithubGraphQLClientInterface $graphqlClient,
        private readonly GithubIssueCacheService $cacheService,
        private readonly GithubRegistryService $registryService,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function fetchIssues(
        User $user,
        ?string $repositoryOwner = null,
        ?string $repositoryName = null,
        string $scope = self::ISSUE_SCOPE_ALL,
    ): array
    {
        $normalizedScope = $this->normalizeScope($scope);
        $token = $this->profileService->requireToken($user);
        $viewerLogin = $this->fetchViewerLogin($token);
        $activeRepositories = $this->registryService->buildCatalog($user, false);
        $normalizedRepository = null;

        if ($normalizedScope === self::ISSUE_SCOPE_REPOSITORY) {
            $runtimeConfiguration = $this->profileService->buildRuntimeConfiguration($user, $repositoryOwner, $repositoryName);
            $issuesPayload = $this->fetchRepositoryIssues($runtimeConfiguration, $viewerLogin, $normalizedScope);
            $normalizedRepository = $issuesPayload['repository'];
            $items = $issuesPayload['items'];
        } else {
            $items = $this->fetchCatalogIssues($token, $activeRepositories, $viewerLogin, $normalizedScope);
        }

        $this->cacheService->syncIssues(
            $user,
            $normalizedScope,
            $items,
            $viewerLogin,
            $normalizedRepository['ownerLogin'] ?? $repositoryOwner,
            $normalizedRepository['name'] ?? $repositoryName,
        );

        return $this->cacheService->buildCachedBoard($user, $normalizedScope, $repositoryOwner, $repositoryName);
    }

    /**
     * @return array<string, mixed>
     */
    public function fetchCachedIssues(
        User $user,
        ?string $repositoryOwner = null,
        ?string $repositoryName = null,
        string $scope = self::ISSUE_SCOPE_ALL,
    ): array {
        return $this->cacheService->buildCachedBoard($user, $scope, $repositoryOwner, $repositoryName);
    }

    /**
     * @return array{item: array<string, mixed>, source: string, warning: ?string}
     */
    public function fetchIssue(User $user, string $issueId): array
    {
        $normalizedIssueId = trim($issueId);
        if ($normalizedIssueId === '') {
            throw new \InvalidArgumentException('The GitHub issue id is required.');
        }

        try {
            $token = $this->profileService->requireToken($user);
            $data = $this->graphqlClient->query($token, self::ISSUE_DETAIL_QUERY, [
                'issueId' => $normalizedIssueId,
            ]);

            $node = $data['node'] ?? null;
            if (!is_array($node) || trim((string) ($node['__typename'] ?? '')) !== 'Issue') {
                throw new GithubGraphQLException('GitHub did not return the requested issue.');
            }

            $normalizedIssue = $this->normalizeIssue($node);
            $cachedIssue = $this->cacheService->upsertIssue($user, $normalizedIssue);

            return [
                'item' => $cachedIssue,
                'source' => 'github',
                'warning' => null,
            ];
        } catch (\Throwable $exception) {
            $cachedIssue = $this->cacheService->findCachedIssue($user, $normalizedIssueId);
            if ($cachedIssue !== null) {
                return [
                    'item' => $cachedIssue,
                    'source' => 'cache',
                    'warning' => sprintf('GitHub detail refresh failed: %s', $exception->getMessage()),
                ];
            }

            if ($exception instanceof \InvalidArgumentException) {
                throw $exception;
            }

            if ($exception instanceof GithubGraphQLException) {
                throw $exception;
            }

            throw new GithubGraphQLException($exception->getMessage(), previous: $exception);
        }
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function updateIssue(User $user, string $issueId, array $payload): array
    {
        $normalizedIssueId = trim($issueId);
        if ($normalizedIssueId === '') {
            throw new \InvalidArgumentException('The GitHub issue id is required.');
        }

        $title = trim((string) ($payload['title'] ?? ''));
        if ($title === '') {
            throw new \InvalidArgumentException('The GitHub issue title is required.');
        }

        $state = strtoupper(trim((string) ($payload['state'] ?? 'OPEN')));
        if (!in_array($state, ['OPEN', 'CLOSED'], true)) {
            throw new \InvalidArgumentException('The GitHub issue state must be OPEN or CLOSED.');
        }

        $body = trim((string) ($payload['body'] ?? ''));
        $token = $this->profileService->requireToken($user);

        $data = $this->graphqlClient->query($token, self::UPDATE_ISSUE_MUTATION, [
            'issueId' => $normalizedIssueId,
            'title' => $title,
            'body' => $body,
            'state' => $state,
        ]);

        $issue = $data['updateIssue']['issue'] ?? null;
        if (!is_array($issue)) {
            throw new GithubGraphQLException('GitHub did not return the updated issue payload.');
        }

        return $this->cacheService->upsertIssue($user, $this->normalizeIssue($issue));
    }

    private function normalizeScope(string $scope): string
    {
        return $this->cacheService->normalizeScope($scope);
    }

    private function fetchViewerLogin(string $token): string
    {
        $data = $this->graphqlClient->query($token, self::VIEWER_LOGIN_QUERY, []);
        $viewer = $data['viewer'] ?? null;
        if (!is_array($viewer)) {
            throw new GithubGraphQLException('GitHub did not return the authenticated viewer profile.');
        }

        $viewerLogin = trim((string) ($viewer['login'] ?? ''));
        if ($viewerLogin === '') {
            throw new GithubGraphQLException('GitHub returned an invalid viewer login.');
        }

        return $viewerLogin;
    }

    /**
     * @return array{repository: array<string, mixed>, items: list<array<string, mixed>>}
     */
    private function fetchRepositoryIssues(GithubRuntimeConfiguration $runtimeConfiguration, string $viewerLogin, string $scope): array
    {
        $items = [];
        $normalizedRepository = null;
        $after = null;

        do {
            $variables = [
                'owner' => $runtimeConfiguration->repositoryOwner,
                'name' => $runtimeConfiguration->repositoryName,
                'after' => $after,
            ];

            if ($scope === self::ISSUE_SCOPE_ASSIGNED) {
                $variables['assignee'] = $viewerLogin;
            }

            $data = $this->graphqlClient->query(
                $runtimeConfiguration->token,
                $scope === self::ISSUE_SCOPE_ASSIGNED ? self::REPOSITORY_ASSIGNED_ISSUES_QUERY : self::REPOSITORY_ALL_ISSUES_QUERY,
                $variables
            );

            $repository = $data['repository'] ?? null;
            if (!is_array($repository)) {
                throw new GithubGraphQLException('GitHub could not find the selected repository.');
            }

            if ($normalizedRepository === null) {
                $normalizedRepository = $this->normalizeRepository($repository);
            }

            $issuesConnection = $repository['issues'] ?? null;
            $issueNodes = is_array($issuesConnection) ? ($issuesConnection['nodes'] ?? []) : [];
            $items = [...$items, ...$this->normalizeIssues($issueNodes)];

            $pageInfo = is_array($issuesConnection) ? ($issuesConnection['pageInfo'] ?? null) : null;
            $hasNextPage = is_array($pageInfo) ? (bool) ($pageInfo['hasNextPage'] ?? false) : false;
            $endCursor = is_array($pageInfo) ? trim((string) ($pageInfo['endCursor'] ?? '')) : '';
            $after = $hasNextPage && $endCursor !== '' ? $endCursor : null;
        } while ($after !== null);

        if ($normalizedRepository === null) {
            throw new GithubGraphQLException('GitHub could not normalize the selected repository.');
        }

        return [
            'repository' => $normalizedRepository,
            'items' => $this->sortIssues($items),
        ];
    }

    /**
     * @param list<array<string, mixed>> $repositories
     *
     * @return list<array<string, mixed>>
     */
    private function fetchCatalogIssues(string $token, array $repositories, string $viewerLogin, string $scope): array
    {
        $items = [];

        foreach ($repositories as $repository) {
            $ownerLogin = trim((string) ($repository['ownerLogin'] ?? ''));
            $repositoryName = trim((string) ($repository['name'] ?? ''));
            if ($ownerLogin === '' || $repositoryName === '') {
                continue;
            }

            $issuesPayload = $this->fetchRepositoryIssues(
                new GithubRuntimeConfiguration($token, $ownerLogin, $repositoryName),
                $viewerLogin,
                $scope
            );

            $items = [...$items, ...$issuesPayload['items']];
        }

        return $this->sortIssues($items);
    }

    /**
     * @param list<array<string, mixed>> $items
     *
     * @return list<array<string, mixed>>
     */
    private function sortIssues(array $items): array
    {
        usort($items, static function (array $left, array $right): int {
            return strcmp((string) ($right['updatedAt'] ?? ''), (string) ($left['updatedAt'] ?? ''));
        });

        return array_values($items);
    }

    /**
     * @param mixed $rawIssues
     *
     * @return list<array<string, mixed>>
     */
    private function normalizeIssues(mixed $rawIssues): array
    {
        if (!is_array($rawIssues)) {
            return [];
        }

        $issues = [];

        foreach ($rawIssues as $rawIssue) {
            if (!is_array($rawIssue)) {
                continue;
            }

            $issue = $this->normalizeIssue($rawIssue);
            if (($issue['id'] ?? '') === '') {
                continue;
            }

            $issues[] = $issue;
        }

        return $issues;
    }

    /**
     * @param array<string, mixed> $rawIssue
     *
     * @return array<string, mixed>
     */
    private function normalizeIssue(array $rawIssue): array
    {
        $state = strtoupper(trim((string) ($rawIssue['state'] ?? 'OPEN')));
        $author = $rawIssue['author'] ?? null;
        $repository = $rawIssue['repository'] ?? null;

        return [
            'id' => trim((string) ($rawIssue['id'] ?? '')),
            'number' => (int) ($rawIssue['number'] ?? 0),
            'title' => trim((string) ($rawIssue['title'] ?? '')),
            'body' => (string) ($rawIssue['body'] ?? ''),
            'state' => $state === 'CLOSED' ? 'CLOSED' : 'OPEN',
            'url' => trim((string) ($rawIssue['url'] ?? '')),
            'createdAt' => trim((string) ($rawIssue['createdAt'] ?? '')),
            'updatedAt' => trim((string) ($rawIssue['updatedAt'] ?? '')),
            'viewerCanUpdate' => (bool) ($rawIssue['viewerCanUpdate'] ?? false),
            'viewerCanClose' => (bool) ($rawIssue['viewerCanClose'] ?? false),
            'viewerCanReopen' => (bool) ($rawIssue['viewerCanReopen'] ?? false),
            'authorLogin' => is_array($author) ? trim((string) ($author['login'] ?? '')) : null,
            'assignees' => $this->normalizeAssignees($rawIssue['assignees']['nodes'] ?? []),
            'labels' => $this->normalizeLabels($rawIssue['labels']['nodes'] ?? []),
            'repository' => [
                'nameWithOwner' => is_array($repository) ? trim((string) ($repository['nameWithOwner'] ?? '')) : '',
                'url' => is_array($repository) ? trim((string) ($repository['url'] ?? '')) : '',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $rawRepository
     *
     * @return array<string, mixed>
     */
    private function normalizeRepository(array $rawRepository): array
    {
        $owner = $rawRepository['owner'] ?? null;

        return [
            'id' => trim((string) ($rawRepository['id'] ?? '')),
            'name' => trim((string) ($rawRepository['name'] ?? '')),
            'nameWithOwner' => trim((string) ($rawRepository['nameWithOwner'] ?? '')),
            'description' => $this->normalizeNullableString($rawRepository['description'] ?? null),
            'url' => trim((string) ($rawRepository['url'] ?? '')),
            'isPrivate' => (bool) ($rawRepository['isPrivate'] ?? false),
            'ownerLogin' => is_array($owner) ? trim((string) ($owner['login'] ?? '')) : '',
        ];
    }

    /**
     * @param mixed $rawAssignees
     *
     * @return list<array<string, mixed>>
     */
    private function normalizeAssignees(mixed $rawAssignees): array
    {
        if (!is_array($rawAssignees)) {
            return [];
        }

        $assignees = [];

        foreach ($rawAssignees as $rawAssignee) {
            if (!is_array($rawAssignee)) {
                continue;
            }

            $login = trim((string) ($rawAssignee['login'] ?? ''));
            if ($login === '') {
                continue;
            }

            $assignees[] = [
                'login' => $login,
                'name' => $this->normalizeNullableString($rawAssignee['name'] ?? null),
                'avatarUrl' => trim((string) ($rawAssignee['avatarUrl'] ?? '')),
                'url' => trim((string) ($rawAssignee['url'] ?? '')),
            ];
        }

        return $assignees;
    }

    /**
     * @param mixed $rawLabels
     *
     * @return list<array<string, mixed>>
     */
    private function normalizeLabels(mixed $rawLabels): array
    {
        if (!is_array($rawLabels)) {
            return [];
        }

        $labels = [];

        foreach ($rawLabels as $rawLabel) {
            if (!is_array($rawLabel)) {
                continue;
            }

            $id = trim((string) ($rawLabel['id'] ?? ''));
            $name = trim((string) ($rawLabel['name'] ?? ''));
            if ($id === '' || $name === '') {
                continue;
            }

            $labels[] = [
                'id' => $id,
                'name' => $name,
                'color' => trim((string) ($rawLabel['color'] ?? '')),
                'description' => $this->normalizeNullableString($rawLabel['description'] ?? null),
            ];
        }

        return $labels;
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }
}
