<?php

namespace App\Github;

use App\Entity\User;
use App\Github\Exception\GithubGraphQLException;

final class GithubAssignedIssueService
{
    private const ISSUE_SCOPE_ASSIGNED = 'assigned';
    private const ISSUE_SCOPE_REPOSITORY = 'repository';

    private const VIEWER_REPOSITORIES_QUERY = <<<'GRAPHQL'
query GithubViewerRepositories {
  viewer {
    login
    repositories(
      first: 30
      affiliations: [OWNER, COLLABORATOR, ORGANIZATION_MEMBER]
      orderBy: {field: UPDATED_AT, direction: DESC}
      ownerAffiliations: [OWNER, COLLABORATOR, ORGANIZATION_MEMBER]
    ) {
      nodes {
        id
        name
        nameWithOwner
        description
        url
        isPrivate
        owner {
          login
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
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function fetchIssues(
        User $user,
        ?string $repositoryOwner = null,
        ?string $repositoryName = null,
        string $scope = self::ISSUE_SCOPE_ASSIGNED,
    ): array
    {
        $runtimeConfiguration = $this->profileService->buildRuntimeConfiguration($user, $repositoryOwner, $repositoryName);
        $viewerData = $this->graphqlClient->query($runtimeConfiguration->token, self::VIEWER_REPOSITORIES_QUERY);
        $viewer = $viewerData['viewer'] ?? null;

        if (!is_array($viewer)) {
            throw new GithubGraphQLException('GitHub did not return the authenticated viewer profile.');
        }

        $viewerLogin = trim((string) ($viewer['login'] ?? ''));
        if ($viewerLogin === '') {
            throw new GithubGraphQLException('GitHub returned an invalid viewer login.');
        }

        $repositories = $this->normalizeRepositoryCatalog($viewer['repositories']['nodes'] ?? []);
        $normalizedScope = $this->normalizeScope($scope);
        $issuesPayload = $this->fetchRepositoryIssues(
            $runtimeConfiguration,
            $viewerLogin,
            $normalizedScope
        );
        $normalizedRepository = $issuesPayload['repository'];

        return [
            'scope' => $normalizedScope,
            'viewer' => [
                'login' => $viewerLogin,
            ],
            'repository' => $normalizedRepository,
            'repositories' => $this->mergeSelectedRepository($normalizedRepository, $repositories),
            'items' => $issuesPayload['items'],
        ];
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

        return $this->normalizeIssue($issue);
    }

    private function normalizeScope(string $scope): string
    {
        $normalizedScope = trim(strtolower($scope));

        if ($normalizedScope === '') {
            return self::ISSUE_SCOPE_ASSIGNED;
        }

        if (!in_array($normalizedScope, [self::ISSUE_SCOPE_ASSIGNED, self::ISSUE_SCOPE_REPOSITORY], true)) {
            throw new \InvalidArgumentException('The GitHub issue scope must be "assigned" or "repository".');
        }

        return $normalizedScope;
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
            'items' => $items,
        ];
    }

    /**
     * @param mixed $rawRepositories
     *
     * @return list<array<string, mixed>>
     */
    private function normalizeRepositoryCatalog(mixed $rawRepositories): array
    {
        if (!is_array($rawRepositories)) {
            return [];
        }

        $repositories = [];

        foreach ($rawRepositories as $rawRepository) {
            if (!is_array($rawRepository)) {
                continue;
            }

            $normalizedRepository = $this->normalizeRepository($rawRepository);
            if (($normalizedRepository['nameWithOwner'] ?? '') === '') {
                continue;
            }

            $repositories[] = $normalizedRepository;
        }

        return array_values($repositories);
    }

    /**
     * @param array<string, mixed> $repository
     * @param list<array<string, mixed>> $repositories
     *
     * @return list<array<string, mixed>>
     */
    private function mergeSelectedRepository(array $repository, array $repositories): array
    {
        $selectedKey = trim((string) ($repository['nameWithOwner'] ?? ''));
        if ($selectedKey === '') {
            return $repositories;
        }

        foreach ($repositories as $availableRepository) {
            if (($availableRepository['nameWithOwner'] ?? null) === $selectedKey) {
                return $repositories;
            }
        }

        array_unshift($repositories, $repository);

        return $repositories;
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
