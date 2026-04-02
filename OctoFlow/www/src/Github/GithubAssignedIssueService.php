<?php

namespace App\Github;

use App\Entity\User;
use App\Github\Exception\GithubActionForbiddenException;
use App\Github\Exception\GithubGraphQLException;

final class GithubAssignedIssueService
{
    private const UPDATE_COMMENT_TIMEZONE = 'America/Sao_Paulo';
    private const DEFAULT_LABEL_COLOR = '94A3B8';

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
      number
      title
      body
      state
      url
      parent {
        id
        number
        title
        state
        url
      }
      subIssues(first: 50) {
        nodes {
          id
          number
          title
          state
          url
          createdAt
          updatedAt
          assignees(first: 10) {
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
        id
        nameWithOwner
        url
        labels(first: 50) {
          nodes {
            id
            name
            color
            description
          }
        }
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
      projectItems(first: 20) {
        nodes {
          project {
            id
            title
            url
          }
        }
      }
    }
  }
}
GRAPHQL;

    private const UPDATE_ISSUE_WITH_LABELS_MUTATION = <<<'GRAPHQL'
mutation GithubUpdateIssueWithLabels($issueId: ID!, $title: String!, $body: String!, $state: IssueState!, $labelIds: [ID!]) {
  updateIssue(input: {id: $issueId, title: $title, body: $body, state: $state, labelIds: $labelIds}) {
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
      parent {
        id
        number
        title
        state
        url
      }
      subIssues(first: 50) {
        nodes {
          id
          number
          title
          state
          url
          createdAt
          updatedAt
          assignees(first: 10) {
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
      projectItems(first: 20) {
        nodes {
          project {
            id
            title
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
      timelineItems(first: 30, itemTypes: [SUB_ISSUE_ADDED_EVENT]) {
        nodes {
          __typename
          ... on SubIssueAddedEvent {
            id
            createdAt
            actor {
              __typename
              ... on User {
                login
              }
              ... on Bot {
                login
              }
              ... on Organization {
                login
              }
              ... on Mannequin {
                login
              }
            }
            subIssue {
              id
              number
              title
              url
              createdAt
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
            }
          }
        }
      }
    }
  }
}
GRAPHQL;

    private const CREATE_ISSUE_MUTATION = <<<'GRAPHQL'
mutation CreateIssue($repositoryId: ID!, $title: String!, $body: String!) {
  createIssue(input: {repositoryId: $repositoryId, title: $title, body: $body}) {
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

    private const ADD_SUB_ISSUE_MUTATION = <<<'GRAPHQL'
mutation AddSubIssue($issueId: ID!, $subIssueId: ID!) {
  addSubIssue(input: {issueId: $issueId, subIssueId: $subIssueId}) {
    issue {
      id
    }
    subIssue {
      id
    }
  }
}
GRAPHQL;

    private const ADD_PROJECT_ITEM_MUTATION = <<<'GRAPHQL'
mutation AddIssueToProject($projectId: ID!, $contentId: ID!) {
  addProjectV2ItemById(input: {projectId: $projectId, contentId: $contentId}) {
    item {
      id
    }
  }
}
GRAPHQL;

    private const CREATE_LABEL_MUTATION = <<<'GRAPHQL'
mutation CreateLabel($repositoryId: ID!, $name: String!, $color: String!) {
  createLabel(input: {repositoryId: $repositoryId, name: $name, color: $color}) {
    label {
      id
      name
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
        private readonly GithubIssueTemplateCatalog $templateCatalog,
        private readonly GithubIssueUpdateTemplateCatalog $updateTemplateCatalog,
        private readonly TemplateAccessService $templateAccessService,
        private readonly GithubIssueBodyRenderer $bodyRenderer,
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
                    'parent' => $normalizedIssue['parent'] ?? null,
                    'subIssues' => $normalizedIssue['subIssues'] ?? [],
                    'projects' => $normalizedIssue['projects'] ?? [],
                    'hasOpenSubIssues' => (bool) ($normalizedIssue['hasOpenSubIssues'] ?? false),
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
        $this->assertViewerCanUpdateIssue($issueUpdateContext);
        $this->assertIssueCanTransitionToState($issueUpdateContext, $state);
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

        $shouldSyncLabels = array_key_exists('labelIds', $payload) || array_key_exists('newLabelNames', $payload);
        $updateIssueMutation = self::UPDATE_ISSUE_MUTATION;
        $updateIssueVariables = [
            'issueId' => $normalizedIssueId,
            'title' => $title,
            'body' => $body,
            'state' => $state,
        ];

        if ($shouldSyncLabels) {
            $resolvedLabelIds = $this->resolveLabelIds(
                $token,
                is_array($issueUpdateContext['repository'] ?? null) ? $issueUpdateContext['repository'] : [],
                $payload['labelIds'] ?? [],
                $payload['newLabelNames'] ?? []
            );
            $updateIssueMutation = self::UPDATE_ISSUE_WITH_LABELS_MUTATION;
            $updateIssueVariables['labelIds'] = $resolvedLabelIds;
        }

        $data = $this->graphqlClient->query($token, $updateIssueMutation, $updateIssueVariables);

        $issue = $data['updateIssue']['issue'] ?? null;
        if (!is_array($issue)) {
            throw new GithubGraphQLException('GitHub did not return the updated issue payload.');
        }

        $issue = $this->assignIssueToCollaborators($token, $issue, $assigneeIds);
        $this->registerIssueUpdateComment($token, $normalizedIssueId, $user);

        $cachedIssue = $this->cacheService->upsertIssue($user, $this->normalizeIssue($issue));

        return [
            ...$cachedIssue,
            'parent' => $this->normalizeIssueReference($issueUpdateContext['parent'] ?? null),
            'subIssues' => is_array($issueUpdateContext['subIssues'] ?? null) ? $issueUpdateContext['subIssues'] : [],
            'projects' => is_array($issueUpdateContext['projects'] ?? null) ? $issueUpdateContext['projects'] : [],
            'hasOpenSubIssues' => (bool) ($issueUpdateContext['hasOpenSubIssues'] ?? false),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function createSubIssues(User $user, string $issueId, array $payload): array
    {
        $normalizedIssueId = trim($issueId);
        if ($normalizedIssueId === '') {
            throw new \InvalidArgumentException('The GitHub issue id is required.');
        }

        $rawItems = $payload['items'] ?? [];
        if (!is_array($rawItems) || $rawItems === []) {
            throw new \InvalidArgumentException('At least one sub-issue must be informed.');
        }

        $token = $this->profileService->requireToken($user);
        $issueUpdateContext = $this->fetchIssueUpdateContext($token, $normalizedIssueId);
        $this->assertViewerCanUpdateIssue($issueUpdateContext);
        $this->assertParentIssueCanReceiveSubIssues($issueUpdateContext);

        $repository = is_array($issueUpdateContext['repository'] ?? null) ? $issueUpdateContext['repository'] : [];
        $repositoryId = trim((string) ($repository['id'] ?? ''));
        if ($repositoryId === '') {
            throw new GithubGraphQLException('GitHub returned an invalid repository id for the parent issue.');
        }

        $createdItems = [];
        $parentDueDate = $this->extractDueDateFromBody((string) ($issueUpdateContext['body'] ?? ''));

        foreach ($rawItems as $index => $rawItem) {
            if (!is_array($rawItem)) {
                throw new \InvalidArgumentException(sprintf('The sub-issue payload at position %d is invalid.', $index + 1));
            }

            $title = trim((string) ($rawItem['title'] ?? ''));
            if ($title === '') {
                throw new \InvalidArgumentException(sprintf('The title of sub-issue %d is required.', $index + 1));
            }

            $templateKey = trim((string) ($rawItem['template'] ?? ''));
            $rawTemplateFields = $rawItem['templateFields'] ?? [];
            if (!is_array($rawTemplateFields)) {
                throw new \InvalidArgumentException(sprintf('The template fields of sub-issue %d are invalid.', $index + 1));
            }

            $body = trim((string) ($rawItem['body'] ?? ''));
            $resolvedTitle = $this->formatSubIssueTitle($title);

            if ($templateKey !== '') {
                $template = $this->templateCatalog->find($templateKey);
                if ($template === null) {
                    throw new \InvalidArgumentException(sprintf('Unknown template for sub-issue %d.', $index + 1));
                }

                if (!$this->templateAccessService->canUseTemplate($user, $template)) {
                    throw new \InvalidArgumentException('The selected GitHub issue template is not available for your profile.');
                }

                $draft = $this->bodyRenderer->render(
                    $template,
                    $title,
                    $rawTemplateFields,
                    $user->getEmail()
                );
                $resolvedTitle = $this->formatSubIssueTitle($draft['title']);
                $body = trim((string) ($draft['body'] ?? ''));
                $manualBody = trim((string) ($rawItem['body'] ?? ''));
                if ($manualBody !== '') {
                    $body = trim($body . "\n\n" . $manualBody);
                }
            }

            $dueDate = $this->normalizeNullableString($rawItem['dueDate'] ?? null);
            if ($dueDate !== null) {
                $this->assertDueDateWithinParentDeadline($dueDate, $parentDueDate, $index + 1);
                $body = $this->appendDueDateToBody($body, $dueDate);
            }

            $assigneeIds = $this->normalizeIdList($rawItem['assigneeIds'] ?? []);
            $this->assertAssignableUsers($assigneeIds, $repository);

            $createdIssueData = $this->graphqlClient->query($token, self::CREATE_ISSUE_MUTATION, [
                'repositoryId' => $repositoryId,
                'title' => $resolvedTitle,
                'body' => $body,
            ]);

            $createdIssue = $createdIssueData['createIssue']['issue'] ?? null;
            if (!is_array($createdIssue)) {
                throw new GithubGraphQLException('GitHub did not return the created sub-issue payload.');
            }

            $createdIssue = $this->assignIssueToCollaborators($token, $createdIssue, $assigneeIds);
            $this->linkSubIssueToParent($token, $normalizedIssueId, trim((string) ($createdIssue['id'] ?? '')));
            $this->syncIssueProjectsFromParent($token, $createdIssue, is_array($issueUpdateContext['projects'] ?? null) ? $issueUpdateContext['projects'] : []);
            $this->registerSubIssueCreationComment(
                $token,
                $normalizedIssueId,
                $createdIssue,
                $user
            );

            $normalizedIssue = $this->normalizeIssue($createdIssue);
            $this->cacheService->upsertIssue($user, $normalizedIssue);
            $createdItems[] = $normalizedIssue;
        }

        $parentPayload = $this->fetchIssue($user, $normalizedIssueId);

        return [
            'item' => $parentPayload['item'],
            'history' => $parentPayload['history'],
            'source' => $parentPayload['source'],
            'warning' => $parentPayload['warning'],
            'createdItems' => $createdItems,
        ];
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
            'number' => (int) ($node['number'] ?? 0),
            'title' => trim((string) ($node['title'] ?? '')),
            'body' => (string) ($node['body'] ?? ''),
            'state' => strtoupper(trim((string) ($node['state'] ?? 'OPEN'))) === 'CLOSED' ? 'CLOSED' : 'OPEN',
            'url' => trim((string) ($node['url'] ?? '')),
            'viewerCanUpdate' => (bool) ($node['viewerCanUpdate'] ?? false),
            'viewerCanClose' => (bool) ($node['viewerCanClose'] ?? false),
            'viewerCanReopen' => (bool) ($node['viewerCanReopen'] ?? false),
            'parent' => $this->normalizeIssueReference($node['parent'] ?? null),
            'subIssues' => $this->normalizeIssueReferences($node['subIssues']['nodes'] ?? []),
            'hasOpenSubIssues' => $this->hasOpenSubIssues($node['subIssues']['nodes'] ?? []),
            'assignees' => $this->normalizeGithubUsers($node['assignees']['nodes'] ?? []),
            'repository' => [
                'id' => is_array($node['repository'] ?? null)
                    ? trim((string) (($node['repository']['id'] ?? '')))
                    : '',
                'nameWithOwner' => is_array($node['repository'] ?? null)
                    ? trim((string) (($node['repository']['nameWithOwner'] ?? '')))
                    : '',
                'url' => is_array($node['repository'] ?? null)
                    ? trim((string) (($node['repository']['url'] ?? '')))
                    : '',
                'labels' => is_array($node['repository'] ?? null)
                    ? $this->normalizeLabels($node['repository']['labels']['nodes'] ?? [])
                    : [],
                'assignableUsers' => is_array($node['repository'] ?? null)
                    ? $this->normalizeGithubUsers($node['repository']['assignableUsers']['nodes'] ?? [])
                    : [],
            ],
            'projects' => $this->normalizeProjects($node['projectItems']['nodes'] ?? []),
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
            'parent' => $this->normalizeIssueReference($rawIssue['parent'] ?? null),
            'subIssues' => $this->normalizeIssueReferences($rawIssue['subIssues']['nodes'] ?? []),
            'hasOpenSubIssues' => $this->hasOpenSubIssues($rawIssue['subIssues']['nodes'] ?? []),
            'projects' => $this->normalizeProjects($rawIssue['projectItems']['nodes'] ?? []),
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
                $rawIssue['comments']['nodes'] ?? [],
                $rawIssue['timelineItems']['nodes'] ?? [],
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
        mixed $rawTimelineItems,
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

        $octoFlowSubIssueRegistry = [];

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
                $commentBody = $this->normalizeNullableString($rawComment['body'] ?? null);
                $registeredSubIssueId = $this->extractOctoFlowSubIssueMarker($commentBody);
                if ($registeredSubIssueId !== null) {
                    $octoFlowSubIssueRegistry[$registeredSubIssueId] = true;
                }

                $history[] = [
                    'id' => $commentId,
                    'kind' => 'comment',
                    'title' => $registeredSubIssueId !== null ? 'Sub-issue criada' : 'Comentario',
                    'actorLogin' => is_array($commentAuthor) ? $this->normalizeNullableString($commentAuthor['login'] ?? null) : null,
                    'createdAt' => $commentCreatedAt,
                    'updatedAt' => trim((string) ($rawComment['updatedAt'] ?? $commentCreatedAt)),
                    'body' => $commentBody,
                    'url' => $this->normalizeNullableString($rawComment['url'] ?? null),
                    'octoflowIssueId' => $registeredSubIssueId,
                ];
            }
        }

        $history = [
            ...$history,
            ...$this->buildSubIssueTimelineHistoryEntries($rawTimelineItems, array_keys($octoFlowSubIssueRegistry)),
        ];

        usort($history, static function (array $left, array $right): int {
            return strcmp((string) ($right['createdAt'] ?? ''), (string) ($left['createdAt'] ?? ''));
        });

        return array_values($history);
    }

    /**
     * @param mixed $rawTimelineItems
     * @param list<string> $octoFlowManagedSubIssueIds
     *
     * @return list<array<string, mixed>>
     */
    private function buildSubIssueTimelineHistoryEntries(mixed $rawTimelineItems, array $octoFlowManagedSubIssueIds): array
    {
        if (!is_array($rawTimelineItems)) {
            return [];
        }

        $historyEntries = [];

        foreach ($rawTimelineItems as $rawTimelineItem) {
            if (!is_array($rawTimelineItem) || trim((string) ($rawTimelineItem['__typename'] ?? '')) !== 'SubIssueAddedEvent') {
                continue;
            }

            $subIssue = is_array($rawTimelineItem['subIssue'] ?? null) ? $rawTimelineItem['subIssue'] : null;
            $subIssueId = trim((string) ($subIssue['id'] ?? ''));
            if ($subIssueId === '' || in_array($subIssueId, $octoFlowManagedSubIssueIds, true)) {
                continue;
            }

            $eventId = trim((string) ($rawTimelineItem['id'] ?? ''));
            $eventCreatedAt = trim((string) ($rawTimelineItem['createdAt'] ?? ''));
            if ($eventId === '' || $eventCreatedAt === '') {
                continue;
            }

            $historyEntries[] = [
                'id' => $eventId,
                'kind' => 'comment',
                'title' => 'Sub-issue criada',
                'actorLogin' => $this->normalizeTimelineActorLogin($rawTimelineItem['actor'] ?? null)
                    ?? $this->normalizeNullableString($subIssue['author']['login'] ?? null),
                'createdAt' => $eventCreatedAt,
                'updatedAt' => $eventCreatedAt,
                'body' => $this->buildSubIssueHistoryBody($subIssue, 'GitHub', $rawTimelineItem['actor'] ?? null),
                'url' => $this->normalizeNullableString($subIssue['url'] ?? null),
                'octoflowIssueId' => $subIssueId,
            ];
        }

        return $historyEntries;
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
            [],
            [],
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
     * @param mixed $rawIssue
     *
     * @return array<string, mixed>|null
     */
    private function normalizeIssueReference(mixed $rawIssue): ?array
    {
        if (!is_array($rawIssue)) {
            return null;
        }

        $issueId = trim((string) ($rawIssue['id'] ?? ''));
        if ($issueId === '') {
            return null;
        }

        $state = strtoupper(trim((string) ($rawIssue['state'] ?? 'OPEN')));

        return [
            'id' => $issueId,
            'number' => (int) ($rawIssue['number'] ?? 0),
            'title' => trim((string) ($rawIssue['title'] ?? '')),
            'state' => $state === 'CLOSED' ? 'CLOSED' : 'OPEN',
            'url' => trim((string) ($rawIssue['url'] ?? '')),
            'createdAt' => trim((string) ($rawIssue['createdAt'] ?? '')),
            'updatedAt' => trim((string) ($rawIssue['updatedAt'] ?? '')),
            'assignees' => $this->normalizeGithubUsers($rawIssue['assignees']['nodes'] ?? []),
        ];
    }

    /**
     * @param mixed $rawIssues
     *
     * @return list<array<string, mixed>>
     */
    private function normalizeIssueReferences(mixed $rawIssues): array
    {
        if (!is_array($rawIssues)) {
            return [];
        }

        $issues = [];

        foreach ($rawIssues as $rawIssue) {
            $normalizedIssue = $this->normalizeIssueReference($rawIssue);
            if ($normalizedIssue === null) {
                continue;
            }

            $issues[] = $normalizedIssue;
        }

        usort($issues, static fn (array $left, array $right): int => $left['number'] <=> $right['number']);

        return array_values($issues);
    }

    /**
     * @param mixed $rawProjectItems
     *
     * @return list<array<string, mixed>>
     */
    private function normalizeProjects(mixed $rawProjectItems): array
    {
        if (!is_array($rawProjectItems)) {
            return [];
        }

        $projects = [];
        $seenProjectIds = [];

        foreach ($rawProjectItems as $rawProjectItem) {
            $project = is_array($rawProjectItem) ? ($rawProjectItem['project'] ?? null) : null;
            if (!is_array($project)) {
                continue;
            }

            $projectId = trim((string) ($project['id'] ?? ''));
            if ($projectId === '' || isset($seenProjectIds[$projectId])) {
                continue;
            }

            $seenProjectIds[$projectId] = true;
            $projects[] = [
                'id' => $projectId,
                'title' => trim((string) ($project['title'] ?? '')),
                'url' => trim((string) ($project['url'] ?? '')),
            ];
        }

        return array_values($projects);
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
     * @param mixed $rawNames
     *
     * @return list<string>
     */
    private function normalizeLabelNameList(mixed $rawNames): array
    {
        if (!is_array($rawNames)) {
            return [];
        }

        $normalizedNames = [];
        $seenNames = [];

        foreach ($rawNames as $rawName) {
            $normalizedName = $this->normalizeLabelName($rawName);
            if ($normalizedName === '') {
                continue;
            }

            $normalizedNameKey = $this->buildLabelNameKey($normalizedName);
            if (isset($seenNames[$normalizedNameKey])) {
                continue;
            }

            $seenNames[$normalizedNameKey] = true;
            $normalizedNames[] = $normalizedName;
        }

        return $normalizedNames;
    }

    /**
     * @param array<string, mixed> $repository
     * @param mixed $rawLabelIds
     * @param mixed $rawNewLabelNames
     *
     * @return list<string>
     */
    private function resolveLabelIds(
        string $token,
        array $repository,
        mixed $rawLabelIds,
        mixed $rawNewLabelNames
    ): array {
        $resolvedLabelIds = $this->normalizeIdList($rawLabelIds);
        $pendingLabelNames = $this->normalizeLabelNameList($rawNewLabelNames);

        if ($pendingLabelNames === []) {
            return array_values(array_unique($resolvedLabelIds));
        }

        $repositoryId = trim((string) ($repository['id'] ?? ''));
        if ($repositoryId === '') {
            throw new GithubGraphQLException('GitHub returned an invalid repository id for label creation.');
        }

        $labelsByName = [];
        foreach (is_array($repository['labels'] ?? null) ? $repository['labels'] : [] as $repositoryLabel) {
            if (!is_array($repositoryLabel)) {
                continue;
            }

            $labelId = trim((string) ($repositoryLabel['id'] ?? ''));
            $labelName = $this->normalizeLabelName($repositoryLabel['name'] ?? null);
            if ($labelId === '' || $labelName === '') {
                continue;
            }

            $labelsByName[$this->buildLabelNameKey($labelName)] = [
                'id' => $labelId,
                'name' => $labelName,
            ];
        }

        foreach ($pendingLabelNames as $pendingLabelName) {
            $pendingLabelNameKey = $this->buildLabelNameKey($pendingLabelName);
            $existingLabel = $labelsByName[$pendingLabelNameKey] ?? null;

            if (is_array($existingLabel) && trim((string) ($existingLabel['id'] ?? '')) !== '') {
                $resolvedLabelIds[] = trim((string) $existingLabel['id']);
                continue;
            }

            $createdLabel = $this->createRepositoryLabel($token, $repositoryId, $pendingLabelName);
            $createdLabelId = trim((string) ($createdLabel['id'] ?? ''));
            if ($createdLabelId === '') {
                throw new GithubGraphQLException('GitHub did not return the created label id.');
            }

            $resolvedLabelIds[] = $createdLabelId;
            $labelsByName[$pendingLabelNameKey] = [
                'id' => $createdLabelId,
                'name' => $this->normalizeLabelName($createdLabel['name'] ?? $pendingLabelName),
            ];
        }

        return array_values(array_unique($resolvedLabelIds));
    }

    private function registerSubIssueCreationComment(string $token, string $parentIssueId, array $subIssue, User $user): void
    {
        $subIssueId = trim((string) ($subIssue['id'] ?? ''));
        if ($subIssueId === '') {
            throw new GithubGraphQLException('GitHub returned an invalid sub-issue id for history registration.');
        }

        $data = $this->graphqlClient->query($token, self::ADD_COMMENT_MUTATION, [
            'issueId' => $parentIssueId,
            'body' => $this->buildOctoFlowSubIssueCommentBody($subIssue, $user),
        ]);

        $commentNode = $data['addComment']['commentEdge']['node'] ?? null;
        if (!is_array($commentNode) || trim((string) ($commentNode['id'] ?? '')) === '') {
            throw new GithubGraphQLException('GitHub did not return the sub-issue history comment payload.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function createRepositoryLabel(string $token, string $repositoryId, string $name): array
    {
        $data = $this->graphqlClient->query($token, self::CREATE_LABEL_MUTATION, [
            'repositoryId' => $repositoryId,
            'name' => $name,
            'color' => self::DEFAULT_LABEL_COLOR,
        ]);

        $label = $data['createLabel']['label'] ?? null;
        if (!is_array($label)) {
            throw new GithubGraphQLException('GitHub did not return the label creation payload.');
        }

        return $label;
    }

    private function normalizeLabelName(mixed $value): string
    {
        $normalizedValue = trim((string) $value);
        if ($normalizedValue === '') {
            return '';
        }

        return (string) preg_replace('/\s+/', ' ', $normalizedValue);
    }

    private function buildLabelNameKey(mixed $value): string
    {
        $normalizedValue = $this->normalizeLabelName($value);
        if ($normalizedValue === '') {
            return '';
        }

        if (function_exists('mb_strtolower')) {
            return mb_strtolower($normalizedValue, 'UTF-8');
        }

        return strtolower($normalizedValue);
    }

    /**
     * @param array<string, mixed> $issueUpdateContext
     */
    private function assertIssueCanTransitionToState(array $issueUpdateContext, string $state): void
    {
        if ($state !== 'CLOSED') {
            return;
        }

        $openSubIssues = array_values(array_filter(
            is_array($issueUpdateContext['subIssues'] ?? null) ? $issueUpdateContext['subIssues'] : [],
            static fn (array $subIssue): bool => strtoupper(trim((string) ($subIssue['state'] ?? 'OPEN'))) !== 'CLOSED'
        ));

        if ($openSubIssues === []) {
            return;
        }

        $labels = array_map(static function (array $subIssue): string {
            $number = (int) ($subIssue['number'] ?? 0);
            $title = trim((string) ($subIssue['title'] ?? 'Sub-issue'));

            return $number > 0 ? sprintf('#%d %s', $number, $title) : $title;
        }, array_slice($openSubIssues, 0, 3));

        $suffix = count($openSubIssues) > 3 ? ' e outras sub-issues abertas.' : '.';

        throw new \InvalidArgumentException(sprintf(
            'Não é possível concluir a issue principal enquanto existirem sub-issues abertas: %s%s',
            implode(', ', $labels),
            $suffix
        ));
    }

    /**
     * @param array<string, mixed> $issueUpdateContext
     */
    private function assertViewerCanUpdateIssue(array $issueUpdateContext): void
    {
        if (($issueUpdateContext['viewerCanUpdate'] ?? false) === true) {
            return;
        }

        throw new GithubActionForbiddenException('You do not have permission to update this GitHub issue.');
    }

    /**
     * @param array<string, mixed> $issueUpdateContext
     */
    private function assertParentIssueCanReceiveSubIssues(array $issueUpdateContext): void
    {
        if ($this->normalizeIssueReference($issueUpdateContext['parent'] ?? null) !== null) {
            throw new \InvalidArgumentException('Sub-issues não podem ter outras sub-issues vinculadas.');
        }
    }

    private function hasOpenSubIssues(mixed $rawSubIssues): bool
    {
        if (!is_array($rawSubIssues)) {
            return false;
        }

        foreach ($rawSubIssues as $rawSubIssue) {
            if (!is_array($rawSubIssue)) {
                continue;
            }

            if (strtoupper(trim((string) ($rawSubIssue['state'] ?? 'OPEN'))) !== 'CLOSED') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $repository
     * @param list<string> $assigneeIds
     */
    private function assertAssignableUsers(array $assigneeIds, array $repository): void
    {
        if ($assigneeIds === []) {
            return;
        }

        $availableAssigneeIds = array_values(array_filter(array_map(
            static fn (array $user): string => trim((string) ($user['id'] ?? '')),
            is_array($repository['assignableUsers'] ?? null) ? $repository['assignableUsers'] : []
        )));

        if ($availableAssigneeIds === []) {
            throw new \InvalidArgumentException('The selected repository did not return assignable collaborators.');
        }

        foreach ($assigneeIds as $assigneeId) {
            if (!in_array($assigneeId, $availableAssigneeIds, true)) {
                throw new \InvalidArgumentException('The selected collaborator cannot be assigned in this repository.');
            }
        }
    }

    private function linkSubIssueToParent(string $token, string $parentIssueId, string $subIssueId): void
    {
        if ($parentIssueId === '' || $subIssueId === '') {
            throw new GithubGraphQLException('GitHub returned an invalid parent or sub-issue identifier.');
        }

        $data = $this->graphqlClient->query($token, self::ADD_SUB_ISSUE_MUTATION, [
            'issueId' => $parentIssueId,
            'subIssueId' => $subIssueId,
        ]);

        $linkedSubIssue = $data['addSubIssue']['subIssue'] ?? null;
        if (!is_array($linkedSubIssue) || trim((string) ($linkedSubIssue['id'] ?? '')) === '') {
            throw new GithubGraphQLException('GitHub did not confirm the sub-issue linkage.');
        }
    }

    /**
     * @param array<string, mixed> $issue
     * @param list<array<string, mixed>> $projects
     */
    private function syncIssueProjectsFromParent(string $token, array $issue, array $projects): void
    {
        $issueId = trim((string) ($issue['id'] ?? ''));
        if ($issueId === '' || $projects === []) {
            return;
        }

        foreach ($projects as $project) {
            $projectId = trim((string) ($project['id'] ?? ''));
            if ($projectId === '') {
                continue;
            }

            $data = $this->graphqlClient->query($token, self::ADD_PROJECT_ITEM_MUTATION, [
                'projectId' => $projectId,
                'contentId' => $issueId,
            ]);

            $projectItem = $data['addProjectV2ItemById']['item'] ?? null;
            if (!is_array($projectItem) || trim((string) ($projectItem['id'] ?? '')) === '') {
                throw new GithubGraphQLException('GitHub did not return the inherited project item payload.');
            }
        }
    }

    private function extractDueDateFromBody(string $body): ?\DateTimeImmutable
    {
        if (preg_match('/##\s+Prazo desejado\s*\R+([0-9]{4}-[0-9]{2}-[0-9]{2})/iu', $body, $matches) !== 1) {
            return null;
        }

        try {
            return new \DateTimeImmutable($matches[1]);
        } catch (\Throwable) {
            return null;
        }
    }

    private function assertDueDateWithinParentDeadline(string $subIssueDueDate, ?\DateTimeImmutable $parentDueDate, int $index): void
    {
        try {
            $normalizedSubIssueDueDate = new \DateTimeImmutable($subIssueDueDate);
        } catch (\Throwable) {
            throw new \InvalidArgumentException(sprintf('A data de entrega da sub-issue %d é inválida.', $index));
        }

        if ($parentDueDate !== null && $normalizedSubIssueDueDate > $parentDueDate) {
            throw new \InvalidArgumentException(sprintf(
                'A data de entrega da sub-issue %d não pode ultrapassar o prazo da issue principal (%s).',
                $index,
                $parentDueDate->format('Y-m-d')
            ));
        }
    }

    private function appendDueDateToBody(string $body, string $dueDate): string
    {
        $sections = [];
        $normalizedBody = trim($body);
        if ($normalizedBody !== '') {
            $sections[] = $normalizedBody;
        }

        $sections[] = sprintf("## Prazo desejado\n%s", $dueDate);

        return implode("\n\n", $sections);
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
            "Atualização registrada automaticamente pelo OctoFlow.\n\n- Data: %s\n- Hora: %s\n- Fuso: %s\n- Usuário: %s",
            $timestamp->format('d/m/Y'),
            $timestamp->format('H:i:s'),
            self::UPDATE_COMMENT_TIMEZONE,
            trim($user->getEmail())
        );
    }

    /**
     * @param array<string, mixed> $subIssue
     */
    private function buildOctoFlowSubIssueCommentBody(array $subIssue, User $user): string
    {
        $subIssueId = trim((string) ($subIssue['id'] ?? ''));
        $creator = trim((string) ($user->getEmail() ?? ''));

        return sprintf(
            "<!-- octoflow:subissue-created:%s -->\n## Sub-issue criada\n\n%s",
            $subIssueId,
            $this->buildSubIssueHistoryBody($subIssue, 'OctoFlow', $creator !== '' ? ['login' => $creator] : null),
        );
    }

    /**
     * @param array<string, mixed> $subIssue
     */
    private function buildSubIssueHistoryBody(array $subIssue, string $sourceLabel, mixed $creator): string
    {
        $subIssueNumber = (int) ($subIssue['number'] ?? 0);
        $subIssueTitle = trim((string) ($subIssue['title'] ?? 'Sub-issue'));
        $subIssueUrl = trim((string) ($subIssue['url'] ?? ''));
        $openedAt = trim((string) ($subIssue['createdAt'] ?? ''));
        $creatorLogin = $this->normalizeTimelineActorLogin($creator)
            ?? $this->normalizeNullableString($subIssue['author']['login'] ?? null)
            ?? 'Sistema';
        $assignees = $this->normalizeGithubUsers($subIssue['assignees']['nodes'] ?? []);
        $responsibleLabel = $assignees !== []
            ? implode(', ', array_map(
                static fn (array $assignee): string => trim((string) ($assignee['name'] ?? '')) !== ''
                    ? sprintf('%s (%s)', $assignee['name'], $assignee['login'])
                    : $assignee['login'],
                $assignees
            ))
            : 'Sem responsável';

        $lines = [
            sprintf('- Sub-issue: %s%s', $subIssueNumber > 0 ? '#' . $subIssueNumber . ' ' : '', $subIssueTitle),
            sprintf('- Data de abertura: %s', $openedAt !== '' ? $openedAt : 'Não informada'),
            sprintf('- Criada por: %s', $creatorLogin),
            sprintf('- Responsável definido: %s', $responsibleLabel),
            sprintf('- Origem: %s', $sourceLabel),
        ];

        if ($subIssueUrl !== '') {
            $lines[] = sprintf('- Link: %s', $subIssueUrl);
        }

        return implode("\n", $lines);
    }

    private function extractOctoFlowSubIssueMarker(?string $body): ?string
    {
        if (!is_string($body) || $body === '') {
            return null;
        }

        if (preg_match('/<!--\s*octoflow:subissue-created:([^>\s]+)\s*-->/', $body, $matches) !== 1) {
            return null;
        }

        $subIssueId = trim((string) ($matches[1] ?? ''));

        return $subIssueId !== '' ? $subIssueId : null;
    }

    private function normalizeTimelineActorLogin(mixed $rawActor): ?string
    {
        if (is_array($rawActor)) {
            return $this->normalizeNullableString($rawActor['login'] ?? null);
        }

        if (is_string($rawActor)) {
            return $this->normalizeNullableString($rawActor);
        }

        return null;
    }

    private function formatSubIssueTitle(string $title): string
    {
        $normalizedTitle = trim($title);
        if ($normalizedTitle === '') {
            return '[sub]';
        }

        if (preg_match('/^\[sub\]/i', $normalizedTitle) === 1) {
            return $normalizedTitle;
        }

        return sprintf('[sub]%s', $normalizedTitle);
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
