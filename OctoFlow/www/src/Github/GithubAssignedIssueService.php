<?php

namespace App\Github;

use App\Entity\User;
use App\Github\Exception\GithubGraphQLException;

final class GithubAssignedIssueService
{
    private const UPDATE_COMMENT_TIMEZONE = 'America/Sao_Paulo';

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

    private const ISSUE_UPDATE_CONTEXT_QUERY = <<<'GRAPHQL'
query GithubIssueUpdateContext($issueId: ID!) {
  node(id: $issueId) {
    __typename
    ... on Issue {
      id
      body
      assignees(first: 10) {
        nodes {
          id
          login
          name
          avatarUrl
          url
        }
      }
      repository {
        nameWithOwner
        url
        assignableUsers(first: 50) {
          nodes {
            id
            login
            name
            avatarUrl
            url
          }
        }
      }
    }
  }
}
GRAPHQL;

    private const ISSUE_DETAIL_QUERY = <<<'GRAPHQL'
query GithubIssueDetail($issueId: ID!) {
  viewer {
    id
    login
    name
    avatarUrl
    url
  }
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
      closedAt
      updatedAt
      viewerCanUpdate
      viewerCanClose
      viewerCanReopen
      author {
        login
      }
      assignees(first: 10) {
        nodes {
          id
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
        assignableUsers(first: 50) {
          nodes {
            id
            login
            name
            avatarUrl
            url
          }
        }
      }
      comments(first: 30) {
        nodes {
          id
          body
          createdAt
          updatedAt
          url
          author {
            login
          }
        }
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
            id
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
            id
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
          id
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

    private const ADD_ASSIGNEES_MUTATION = <<<'GRAPHQL'
mutation AddAssigneesToIssue($issueId: ID!, $assigneeIds: [ID!]!) {
  addAssigneesToAssignable(input: {assignableId: $issueId, assigneeIds: $assigneeIds}) {
    assignable {
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
            id
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
}
GRAPHQL;

    private const ADD_COMMENT_MUTATION = <<<'GRAPHQL'
mutation AddCommentToIssue($issueId: ID!, $body: String!) {
  addComment(input: {subjectId: $issueId, body: $body}) {
    commentEdge {
      node {
        id
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
        private readonly GithubIssueUpdateTemplateCatalog $updateTemplateCatalog,
        private readonly TemplateAccessService $templateAccessService,
        private readonly GithubIssueUpdateRenderer $updateRenderer,
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
     * @return array{item: array<string, mixed>, history: list<array<string, mixed>>, source: string, warning: ?string}
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

            $viewerUser = $this->normalizeGithubUser($data['viewer'] ?? null);
            $normalizedIssue = $this->normalizeIssue($node);
            $normalizedIssue['repository']['assignableUsers'] = $this->mergeGithubUser(
                is_array($normalizedIssue['repository']['assignableUsers'] ?? null) ? $normalizedIssue['repository']['assignableUsers'] : [],
                $viewerUser
            );
            $cachedIssue = $this->cacheService->upsertIssue($user, $normalizedIssue);

            return [
                'item' => [
                    ...$cachedIssue,
                    'closedAt' => $normalizedIssue['closedAt'] ?? null,
                    'repository' => [
                        ...(is_array($cachedIssue['repository'] ?? null) ? $cachedIssue['repository'] : []),
                        'assignableUsers' => $normalizedIssue['repository']['assignableUsers'] ?? [],
                    ],
                ],
                'history' => $normalizedIssue['history'] ?? [],
                'source' => 'github',
                'warning' => null,
            ];
        } catch (\Throwable $exception) {
            $cachedIssue = $this->cacheService->findCachedIssue($user, $normalizedIssueId);
            if ($cachedIssue !== null) {
                return [
                    'item' => $cachedIssue,
                    'history' => $this->buildFallbackHistory($cachedIssue),
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

        $assigneeIds = $this->normalizeIdList($payload['assigneeIds'] ?? []);
        $token = $this->profileService->requireToken($user);
        $templateKey = trim((string) ($payload['templateKey'] ?? ''));
        $additionalNotes = trim((string) ($payload['additionalNotes'] ?? ''));
        $legacyBody = trim((string) ($payload['body'] ?? ''));
        $templateFields = $payload['templateFields'] ?? [];
        if (!is_array($templateFields)) {
            throw new \InvalidArgumentException('The issue update template fields payload is invalid.');
        }

        $issueUpdateContext = $this->fetchIssueUpdateContext($token, $normalizedIssueId);
        $body = $legacyBody;

        if ($templateKey !== '' || $additionalNotes !== '' || $legacyBody === '') {
            $template = null;

            if ($templateKey !== '') {
                $template = $this->updateTemplateCatalog->find($templateKey);
                if ($template === null) {
                    throw new \InvalidArgumentException('Unknown GitHub issue update template.');
                }

                if (!$this->templateAccessService->canUseTemplate($user, $template)) {
                    throw new \InvalidArgumentException('The selected GitHub issue update template is not available for your profile.');
                }

                $template = $this->enrichUpdateTemplateWithAssignableOptions($template, $issueUpdateContext);
            }

            $body = $this->updateRenderer->render(
                $template,
                $templateFields,
                (string) ($issueUpdateContext['body'] ?? ''),
                $additionalNotes,
                (string) ($issueUpdateContext['repository']['nameWithOwner'] ?? ''),
            );
        }

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

        $issue = $this->assignIssueToCollaborators($token, $issue, $assigneeIds);
        $this->registerIssueUpdateComment($token, $normalizedIssueId, $user);

        return $this->cacheService->upsertIssue($user, $this->normalizeIssue($issue));
    }

    private function normalizeScope(string $scope): string
    {
        return $this->cacheService->normalizeScope($scope);
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchIssueUpdateContext(string $token, string $issueId): array
    {
        $data = $this->graphqlClient->query($token, self::ISSUE_UPDATE_CONTEXT_QUERY, [
            'issueId' => $issueId,
        ]);

        $node = $data['node'] ?? null;
        if (!is_array($node) || ($node['__typename'] ?? null) !== 'Issue') {
            throw new GithubGraphQLException('GitHub did not return the selected issue update context.');
        }

        return [
            'id' => trim((string) ($node['id'] ?? '')),
            'body' => (string) ($node['body'] ?? ''),
            'assignees' => $this->normalizeGithubUsers($node['assignees']['nodes'] ?? []),
            'repository' => [
                'nameWithOwner' => is_array($node['repository'] ?? null)
                    ? trim((string) (($node['repository']['nameWithOwner'] ?? '')))
                    : '',
                'url' => is_array($node['repository'] ?? null)
                    ? trim((string) (($node['repository']['url'] ?? '')))
                    : '',
                'assignableUsers' => is_array($node['repository'] ?? null)
                    ? $this->normalizeGithubUsers($node['repository']['assignableUsers']['nodes'] ?? [])
                    : [],
            ],
        ];
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
        $authorLogin = is_array($author) ? trim((string) ($author['login'] ?? '')) : '';
        $createdAt = trim((string) ($rawIssue['createdAt'] ?? ''));
        $updatedAt = trim((string) ($rawIssue['updatedAt'] ?? ''));
        $closedAt = $this->normalizeNullableString($rawIssue['closedAt'] ?? null);

        return [
            'id' => trim((string) ($rawIssue['id'] ?? '')),
            'number' => (int) ($rawIssue['number'] ?? 0),
            'title' => trim((string) ($rawIssue['title'] ?? '')),
            'body' => (string) ($rawIssue['body'] ?? ''),
            'state' => $state === 'CLOSED' ? 'CLOSED' : 'OPEN',
            'url' => trim((string) ($rawIssue['url'] ?? '')),
            'createdAt' => $createdAt,
            'updatedAt' => $updatedAt,
            'closedAt' => $closedAt,
            'viewerCanUpdate' => (bool) ($rawIssue['viewerCanUpdate'] ?? false),
            'viewerCanClose' => (bool) ($rawIssue['viewerCanClose'] ?? false),
            'viewerCanReopen' => (bool) ($rawIssue['viewerCanReopen'] ?? false),
            'authorLogin' => $authorLogin !== '' ? $authorLogin : null,
            'assignees' => $this->normalizeGithubUsers($rawIssue['assignees']['nodes'] ?? []),
            'labels' => $this->normalizeLabels($rawIssue['labels']['nodes'] ?? []),
            'repository' => [
                'nameWithOwner' => is_array($repository) ? trim((string) ($repository['nameWithOwner'] ?? '')) : '',
                'url' => is_array($repository) ? trim((string) ($repository['url'] ?? '')) : '',
                'assignableUsers' => is_array($repository) ? $this->normalizeGithubUsers($repository['assignableUsers']['nodes'] ?? []) : [],
            ],
            'history' => $this->buildIssueHistory(
                trim((string) ($rawIssue['id'] ?? '')),
                $authorLogin,
                $createdAt,
                $updatedAt,
                $closedAt,
                (string) ($rawIssue['state'] ?? 'OPEN'),
                $rawIssue['comments']['nodes'] ?? []
            ),
        ];
    }

    /**
     * @param mixed $rawComments
     *
     * @return list<array<string, mixed>>
     */
    private function buildIssueHistory(
        string $issueId,
        string $authorLogin,
        string $createdAt,
        string $updatedAt,
        ?string $closedAt,
        string $state,
        mixed $rawComments,
    ): array {
        $history = [];
        $normalizedIssueId = trim($issueId);
        $normalizedAuthorLogin = trim($authorLogin);
        $normalizedCreatedAt = trim($createdAt);
        $normalizedUpdatedAt = trim($updatedAt);
        $normalizedClosedAt = trim((string) ($closedAt ?? ''));

        if ($normalizedCreatedAt !== '') {
            $history[] = [
                'id' => sprintf('%s-created', $normalizedIssueId !== '' ? $normalizedIssueId : 'issue'),
                'kind' => 'created',
                'title' => 'Issue criada',
                'actorLogin' => $normalizedAuthorLogin !== '' ? $normalizedAuthorLogin : null,
                'createdAt' => $normalizedCreatedAt,
                'updatedAt' => $normalizedCreatedAt,
                'body' => null,
                'url' => null,
            ];
        }

        if ($normalizedUpdatedAt !== '' && $normalizedUpdatedAt !== $normalizedCreatedAt) {
            $history[] = [
                'id' => sprintf('%s-updated', $normalizedIssueId !== '' ? $normalizedIssueId : 'issue'),
                'kind' => 'updated',
                'title' => 'Issue atualizada',
                'actorLogin' => null,
                'createdAt' => $normalizedUpdatedAt,
                'updatedAt' => $normalizedUpdatedAt,
                'body' => null,
                'url' => null,
            ];
        }

        if (strtoupper(trim($state)) === 'CLOSED' && $normalizedClosedAt !== '') {
            $history[] = [
                'id' => sprintf('%s-closed', $normalizedIssueId !== '' ? $normalizedIssueId : 'issue'),
                'kind' => 'closed',
                'title' => 'Issue fechada',
                'actorLogin' => null,
                'createdAt' => $normalizedClosedAt,
                'updatedAt' => $normalizedClosedAt,
                'body' => null,
                'url' => null,
            ];
        }

        if (is_array($rawComments)) {
            foreach ($rawComments as $rawComment) {
                if (!is_array($rawComment)) {
                    continue;
                }

                $commentId = trim((string) ($rawComment['id'] ?? ''));
                $commentCreatedAt = trim((string) ($rawComment['createdAt'] ?? ''));
                if ($commentId === '' || $commentCreatedAt === '') {
                    continue;
                }

                $commentAuthor = $rawComment['author'] ?? null;
                $history[] = [
                    'id' => $commentId,
                    'kind' => 'comment',
                    'title' => 'Comentario',
                    'actorLogin' => is_array($commentAuthor) ? $this->normalizeNullableString($commentAuthor['login'] ?? null) : null,
                    'createdAt' => $commentCreatedAt,
                    'updatedAt' => trim((string) ($rawComment['updatedAt'] ?? $commentCreatedAt)),
                    'body' => $this->normalizeNullableString($rawComment['body'] ?? null),
                    'url' => $this->normalizeNullableString($rawComment['url'] ?? null),
                ];
            }
        }

        usort($history, static function (array $left, array $right): int {
            return strcmp((string) ($right['createdAt'] ?? ''), (string) ($left['createdAt'] ?? ''));
        });

        return array_values($history);
    }

    /**
     * @param array<string, mixed> $issue
     *
     * @return list<array<string, mixed>>
     */
    private function buildFallbackHistory(array $issue): array
    {
        return $this->buildIssueHistory(
            trim((string) ($issue['id'] ?? '')),
            trim((string) ($issue['authorLogin'] ?? '')),
            trim((string) ($issue['createdAt'] ?? '')),
            trim((string) ($issue['updatedAt'] ?? '')),
            $this->normalizeNullableString($issue['closedAt'] ?? null),
            (string) ($issue['state'] ?? 'OPEN'),
            []
        );
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
    private function normalizeGithubUsers(mixed $rawUsers): array
    {
        if (!is_array($rawUsers)) {
            return [];
        }

        $users = [];

        foreach ($rawUsers as $rawUser) {
            if (!is_array($rawUser)) {
                continue;
            }

            $login = trim((string) ($rawUser['login'] ?? ''));
            if ($login === '') {
                continue;
            }

            $users[] = [
                'id' => trim((string) ($rawUser['id'] ?? '')),
                'login' => $login,
                'name' => $this->normalizeNullableString($rawUser['name'] ?? null),
                'avatarUrl' => trim((string) ($rawUser['avatarUrl'] ?? '')),
                'url' => trim((string) ($rawUser['url'] ?? '')),
            ];
        }

        return $users;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function normalizeGithubUser(mixed $rawUser): ?array
    {
        if (!is_array($rawUser)) {
            return null;
        }

        $login = trim((string) ($rawUser['login'] ?? ''));
        if ($login === '') {
            return null;
        }

        return [
            'id' => trim((string) ($rawUser['id'] ?? '')),
            'login' => $login,
            'name' => $this->normalizeNullableString($rawUser['name'] ?? null),
            'avatarUrl' => trim((string) ($rawUser['avatarUrl'] ?? '')),
            'url' => trim((string) ($rawUser['url'] ?? '')),
        ];
    }

    /**
     * @param list<array<string, mixed>> $users
     *
     * @return list<array<string, mixed>>
     */
    private function mergeGithubUser(array $users, ?array $candidate): array
    {
        if (!is_array($candidate)) {
            return $users;
        }

        $candidateId = trim((string) ($candidate['id'] ?? ''));
        $candidateLogin = trim((string) ($candidate['login'] ?? ''));
        if ($candidateId === '' && $candidateLogin === '') {
            return $users;
        }

        foreach ($users as $user) {
            $userId = trim((string) ($user['id'] ?? ''));
            $userLogin = trim((string) ($user['login'] ?? ''));

            if (($candidateId !== '' && $userId === $candidateId) || ($candidateLogin !== '' && $userLogin === $candidateLogin)) {
                return $users;
            }
        }

        $users[] = $candidate;

        return array_values($users);
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

    /**
     * @param mixed $rawIds
     *
     * @return list<string>
     */
    private function normalizeIdList(mixed $rawIds): array
    {
        if (!is_array($rawIds)) {
            return [];
        }

        $ids = [];

        foreach ($rawIds as $rawId) {
            $normalizedId = trim((string) $rawId);
            if ($normalizedId !== '') {
                $ids[] = $normalizedId;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param array<string, mixed> $template
     * @param array<string, mixed> $issueUpdateContext
     *
     * @return array<string, mixed>
     */
    private function enrichUpdateTemplateWithAssignableOptions(array $template, array $issueUpdateContext): array
    {
        $assignableUsers = $issueUpdateContext['repository']['assignableUsers'] ?? [];
        $currentAssignees = $issueUpdateContext['assignees'] ?? [];
        $collaboratorOptions = $this->buildAssignableUserOptions(
            is_array($assignableUsers) ? $assignableUsers : [],
            is_array($currentAssignees) ? $currentAssignees : [],
        );

        return [
            ...$template,
            'fields' => array_map(function (mixed $field) use ($collaboratorOptions): mixed {
                if (!is_array($field) || !$this->isTemplateAssigneeField($field)) {
                    return $field;
                }

                return [
                    ...$field,
                    'type' => 'select',
                    'options' => $collaboratorOptions,
                ];
            }, is_array($template['fields'] ?? null) ? $template['fields'] : []),
        ];
    }

    /**
     * @param list<array<string, mixed>> $assignableUsers
     * @param list<array<string, mixed>> $currentAssignees
     *
     * @return list<array{value: string, label: string}>
     */
    private function buildAssignableUserOptions(array $assignableUsers, array $currentAssignees): array
    {
        $normalizedOptions = [];
        $seenOptionValues = [];
        $collaborators = [...$currentAssignees, ...$assignableUsers];

        foreach ($collaborators as $collaborator) {
            $collaboratorValue = trim((string) ($collaborator['id'] ?? ''));
            if ($collaboratorValue === '' || in_array($collaboratorValue, $seenOptionValues, true)) {
                continue;
            }

            $seenOptionValues[] = $collaboratorValue;
            $collaboratorName = trim((string) ($collaborator['name'] ?? ''));
            $collaboratorLogin = trim((string) ($collaborator['login'] ?? ''));

            $normalizedOptions[] = [
                'value' => $collaboratorValue,
                'label' => $collaboratorName !== ''
                    ? sprintf('%s (%s)', $collaboratorName, $collaboratorLogin)
                    : $collaboratorLogin,
            ];
        }

        return array_values($normalizedOptions);
    }

    /**
     * @param array<string, mixed> $field
     */
    private function isTemplateAssigneeField(array $field): bool
    {
        $fieldKey = trim((string) ($field['key'] ?? ''));

        return $fieldKey === 'owner' || $fieldKey === 'nextOwner';
    }

    /**
     * @param array<string, mixed> $issue
     * @param list<string> $assigneeIds
     *
     * @return array<string, mixed>
     */
    private function assignIssueToCollaborators(string $token, array $issue, array $assigneeIds): array
    {
        if ($assigneeIds === []) {
            return $issue;
        }

        $issueId = trim((string) ($issue['id'] ?? ''));
        if ($issueId === '') {
            throw new GithubGraphQLException('GitHub returned an invalid issue id for collaborator assignment.');
        }

        $existingAssigneeIds = $this->extractAssigneeIdsFromIssue($issue);
        $missingAssigneeIds = array_values(array_filter(
            $assigneeIds,
            static fn (string $assigneeId): bool => !in_array($assigneeId, $existingAssigneeIds, true)
        ));

        if ($missingAssigneeIds === []) {
            return $issue;
        }

        $data = $this->graphqlClient->query($token, self::ADD_ASSIGNEES_MUTATION, [
            'issueId' => $issueId,
            'assigneeIds' => $missingAssigneeIds,
        ]);

        $updatedIssue = $data['addAssigneesToAssignable']['assignable'] ?? null;
        if (!is_array($updatedIssue)) {
            throw new GithubGraphQLException('GitHub did not return the collaborator assignment payload.');
        }

        return $updatedIssue;
    }

    private function registerIssueUpdateComment(string $token, string $issueId, User $user): void
    {
        $data = $this->graphqlClient->query($token, self::ADD_COMMENT_MUTATION, [
            'issueId' => $issueId,
            'body' => $this->buildIssueUpdateCommentBody($user),
        ]);

        $commentNode = $data['addComment']['commentEdge']['node'] ?? null;
        if (!is_array($commentNode) || trim((string) ($commentNode['id'] ?? '')) === '') {
            throw new GithubGraphQLException('GitHub did not return the update comment payload.');
        }
    }

    private function buildIssueUpdateCommentBody(User $user): string
    {
        $timestamp = new \DateTimeImmutable('now', new \DateTimeZone(self::UPDATE_COMMENT_TIMEZONE));

        return sprintf(
            "Atualizacao registrada automaticamente pelo OctoFlow.\n\n- Data: %s\n- Hora: %s\n- Fuso: %s\n- Usuario: %s",
            $timestamp->format('d/m/Y'),
            $timestamp->format('H:i:s'),
            self::UPDATE_COMMENT_TIMEZONE,
            trim($user->getEmail())
        );
    }

    /**
     * @param array<string, mixed> $issue
     *
     * @return list<string>
     */
    private function extractAssigneeIdsFromIssue(array $issue): array
    {
        $assigneeNodes = $issue['assignees']['nodes'] ?? [];
        if (!is_array($assigneeNodes)) {
            return [];
        }

        $assigneeIds = [];

        foreach ($assigneeNodes as $assigneeNode) {
            if (!is_array($assigneeNode)) {
                continue;
            }

            $assigneeId = trim((string) ($assigneeNode['id'] ?? ''));
            if ($assigneeId !== '') {
                $assigneeIds[] = $assigneeId;
            }
        }

        return array_values(array_unique($assigneeIds));
    }
}
