<?php

namespace App\Github;

use App\Entity\User;
use App\Github\Exception\GithubGraphQLException;

final class GithubIssueService implements GithubIssuePublisherInterface
{
    private const DEFAULT_LABEL_COLOR = '0EA5E9';

    private const CREATE_ISSUE_MUTATION = <<<'GRAPHQL'
mutation CreateIssue($repositoryId: ID!, $title: String!, $body: String!, $labelIds: [ID!]) {
  createIssue(input: {repositoryId: $repositoryId, title: $title, body: $body, labelIds: $labelIds}) {
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

    private const CREATE_LABEL_MUTATION = <<<'GRAPHQL'
mutation CreateLabel($repositoryId: ID!, $name: String!, $color: String!) {
  createLabel(input: {repositoryId: $repositoryId, name: $name, color: $color}) {
    label {
      id
      name
      color
      description
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

    private const ADD_PROJECT_ITEM_MUTATION = <<<'GRAPHQL'
mutation AddIssueToProject($projectId: ID!, $contentId: ID!) {
  addProjectV2ItemById(input: {projectId: $projectId, contentId: $contentId}) {
    item {
      id
    }
  }
}
GRAPHQL;

    private const UPDATE_PROJECT_STATUS_MUTATION = <<<'GRAPHQL'
mutation UpdateProjectStatus($projectId: ID!, $itemId: ID!, $fieldId: ID!, $statusOptionId: String!) {
  updateProjectV2ItemFieldValue(
    input: {
      projectId: $projectId
      itemId: $itemId
      fieldId: $fieldId
      value: {singleSelectOptionId: $statusOptionId}
    }
  ) {
    projectV2Item {
      id
    }
  }
}
GRAPHQL;

    public function __construct(
        private readonly GithubWorkspaceService $workspaceService,
        private readonly GithubProfileService $profileService,
        private readonly GithubGraphQLClientInterface $graphqlClient,
        private readonly GithubIssueTemplateCatalog $templateCatalog,
        private readonly TemplateAccessService $templateAccessService,
        private readonly GithubIssueBodyRenderer $bodyRenderer,
        private readonly GithubIssueCacheService $cacheService,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function createIssue(User $user, array $payload): array
    {
        $templateKey = trim((string) ($payload['template'] ?? ''));
        $template = $this->templateCatalog->find($templateKey);
        if ($template === null) {
            throw new \InvalidArgumentException('Unknown GitHub issue template.');
        }

        if (!$this->templateAccessService->canUseTemplate($user, $template)) {
            throw new \InvalidArgumentException('The selected GitHub issue template is not available for your profile.');
        }

        $repositorySelection = $this->normalizeRepositorySelection($payload);

        $rawFieldValues = $payload['fields'] ?? [];
        if (!is_array($rawFieldValues)) {
            throw new \InvalidArgumentException('The issue fields payload is invalid.');
        }

        $draft = $this->bodyRenderer->render(
            $template,
            (string) ($payload['title'] ?? ''),
            $rawFieldValues,
            $user->getEmail()
        );

        $token = $this->profileService->requireToken($user);
        $repository = $this->workspaceService->fetchRepository(
            $user,
            $repositorySelection['repositoryOwner'],
            $repositorySelection['repositoryName']
        );
        $requestedAssigneeIds = $this->normalizeIdList($payload['assigneeIds'] ?? []);
        $this->assertAssignableUsers($requestedAssigneeIds, $repository);
        $projectSelection = $this->normalizeProjectSelection($payload);
        $validatedProject = $this->validateProjectSelection(
            $user,
            (string) ($repository['ownerLogin'] ?? ''),
            $projectSelection['projectId'],
            $projectSelection['statusOptionId']
        );
        $resolvedLabelIds = $this->resolveLabelIds(
            $token,
            $repository,
            $payload['labelIds'] ?? [],
            $payload['newLabelNames'] ?? []
        );

        $issueData = $this->graphqlClient->query($token, self::CREATE_ISSUE_MUTATION, [
            'repositoryId' => (string) ($repository['id'] ?? ''),
            'title' => $draft['title'],
            'body' => $draft['body'],
            'labelIds' => $resolvedLabelIds,
        ]);

        $issue = $issueData['createIssue']['issue'] ?? null;
        if (!is_array($issue)) {
            throw new GithubGraphQLException('GitHub did not return the created issue payload.');
        }

        $issue = $this->assignIssueToCollaborators($token, $issue, $requestedAssigneeIds);
        $cachedIssue = $this->cacheService->upsertIssue($user, $this->normalizeIssue($issue));

        $projectResult = [
            'projectId' => $projectSelection['projectId'],
            'attached' => false,
            'statusUpdated' => false,
            'message' => null,
        ];

        if ($validatedProject !== null) {
            try {
                $projectResult = $this->attachIssueToProject(
                    $token,
                    $issue,
                    $validatedProject,
                    $projectSelection['statusOptionId']
                );
            } catch (GithubGraphQLException $exception) {
                $projectResult = [
                    'projectId' => $validatedProject['id'],
                    'attached' => false,
                    'statusUpdated' => false,
                    'message' => sprintf('Issue created, but the project sync failed: %s', $exception->getMessage()),
                ];
            }
        }

        return [
            'issue' => [
                'id' => (string) ($issue['id'] ?? ''),
                'number' => (int) ($issue['number'] ?? 0),
                'title' => (string) ($issue['title'] ?? ''),
                'url' => (string) ($issue['url'] ?? ''),
                'createdAt' => (string) ($issue['createdAt'] ?? ''),
            ],
            'repository' => [
                'id' => (string) ($repository['id'] ?? ''),
                'name' => (string) ($repository['name'] ?? ''),
                'nameWithOwner' => (string) ($repository['nameWithOwner'] ?? ''),
                'url' => (string) ($repository['url'] ?? ''),
            ],
            'item' => $cachedIssue,
            'project' => $projectResult,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function createDraftIssue(User $user, array $payload): array
    {
        $title = trim((string) ($payload['title'] ?? ''));
        $body = trim((string) ($payload['body'] ?? ''));
        if ($title === '') {
            throw new \InvalidArgumentException('The GitHub issue title is required.');
        }

        if ($body === '') {
            throw new \InvalidArgumentException('The GitHub issue body is required.');
        }

        $repositorySelection = $this->normalizeRepositorySelection($payload);
        $token = $this->profileService->requireToken($user);
        $repository = $this->workspaceService->fetchRepository(
            $user,
            $repositorySelection['repositoryOwner'],
            $repositorySelection['repositoryName']
        );
        $resolvedLabelIds = $this->resolveLabelIds(
            $token,
            $repository,
            $payload['labelIds'] ?? [],
            $payload['newLabelNames'] ?? []
        );

        $issueData = $this->graphqlClient->query($token, self::CREATE_ISSUE_MUTATION, [
            'repositoryId' => (string) ($repository['id'] ?? ''),
            'title' => $title,
            'body' => $body,
            'labelIds' => $resolvedLabelIds,
        ]);

        $issue = $issueData['createIssue']['issue'] ?? null;
        if (!is_array($issue)) {
            throw new GithubGraphQLException('GitHub did not return the created issue payload.');
        }

        $cachedIssue = $this->cacheService->upsertIssue($user, $this->normalizeIssue($issue));

        return [
            'issue' => [
                'id' => (string) ($issue['id'] ?? ''),
                'number' => (int) ($issue['number'] ?? 0),
                'title' => (string) ($issue['title'] ?? ''),
                'url' => (string) ($issue['url'] ?? ''),
                'createdAt' => (string) ($issue['createdAt'] ?? ''),
            ],
            'repository' => [
                'id' => (string) ($repository['id'] ?? ''),
                'name' => (string) ($repository['name'] ?? ''),
                'nameWithOwner' => (string) ($repository['nameWithOwner'] ?? ''),
                'url' => (string) ($repository['url'] ?? ''),
            ],
            'item' => $cachedIssue,
            'project' => [
                'projectId' => null,
                'attached' => false,
                'statusUpdated' => false,
                'message' => null,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array{projectId: ?string, statusOptionId: ?string}
     */
    private function normalizeProjectSelection(array $payload): array
    {
        $projectId = trim((string) ($payload['projectId'] ?? ''));
        $statusOptionId = trim((string) ($payload['statusOptionId'] ?? ''));

        if ($projectId === '' && $statusOptionId !== '') {
            throw new \InvalidArgumentException('Select a GitHub project before choosing a project status.');
        }

        return [
            'projectId' => $projectId !== '' ? $projectId : null,
            'statusOptionId' => $statusOptionId !== '' ? $statusOptionId : null,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array{repositoryOwner: ?string, repositoryName: ?string}
     */
    private function normalizeRepositorySelection(array $payload): array
    {
        $repositoryOwner = trim((string) ($payload['repositoryOwner'] ?? ''));
        $repositoryName = trim((string) ($payload['repositoryName'] ?? ''));

        if (($repositoryOwner === '') xor ($repositoryName === '')) {
            throw new \InvalidArgumentException('Inform the GitHub repository owner and name together.');
        }

        return [
            'repositoryOwner' => $repositoryOwner !== '' ? $repositoryOwner : null,
            'repositoryName' => $repositoryName !== '' ? $repositoryName : null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function validateProjectSelection(User $user, string $ownerLogin, ?string $projectId, ?string $statusOptionId): ?array
    {
        if ($projectId === null) {
            return null;
        }

        $project = null;

        foreach ($this->workspaceService->fetchProjectsForRepositoryOwner($user, $ownerLogin) as $availableProject) {
            if (($availableProject['id'] ?? null) === $projectId) {
                $project = $availableProject;
                break;
            }
        }

        if ($project === null) {
            throw new \InvalidArgumentException('The selected GitHub project is not available for the configured repository owner.');
        }

        if ($statusOptionId === null) {
            return $project;
        }

        $statusField = $project['statusField'] ?? null;
        if (!is_array($statusField)) {
            throw new \InvalidArgumentException('The selected GitHub project does not expose a Status field.');
        }

        $availableOptions = $statusField['options'] ?? [];
        if (!is_array($availableOptions)) {
            throw new \InvalidArgumentException('The selected GitHub project returned an invalid Status field configuration.');
        }

        foreach ($availableOptions as $availableOption) {
            if (is_array($availableOption) && ($availableOption['id'] ?? null) === $statusOptionId) {
                return $project;
            }
        }

        throw new \InvalidArgumentException('The selected GitHub project status is not available.');
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

        $names = [];
        $seenNames = [];

        foreach ($rawNames as $rawName) {
            $normalizedName = $this->normalizeLabelName($rawName);
            if ($normalizedName === '') {
                continue;
            }

            $nameKey = $this->buildLabelNameKey($normalizedName);
            if (isset($seenNames[$nameKey])) {
                continue;
            }

            $seenNames[$nameKey] = true;
            $names[] = $normalizedName;
        }

        return $names;
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
            return $resolvedLabelIds;
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

        foreach ($pendingLabelNames as $labelName) {
            $labelNameKey = $this->buildLabelNameKey($labelName);
            $existingLabel = $labelsByName[$labelNameKey] ?? null;

            if (is_array($existingLabel) && trim((string) ($existingLabel['id'] ?? '')) !== '') {
                $resolvedLabelIds[] = trim((string) $existingLabel['id']);
                continue;
            }

            $createdLabel = $this->createRepositoryLabel($token, $repositoryId, $labelName);
            $createdLabelId = trim((string) ($createdLabel['id'] ?? ''));
            if ($createdLabelId === '') {
                throw new GithubGraphQLException('GitHub did not return the created label id.');
            }

            $resolvedLabelIds[] = $createdLabelId;
            $labelsByName[$labelNameKey] = [
                'id' => $createdLabelId,
                'name' => $this->normalizeLabelName($createdLabel['name'] ?? $labelName),
            ];
        }

        return array_values(array_unique($resolvedLabelIds));
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
     * @param array<string, mixed> $issue
     * @param array<string, mixed> $project
     *
     * @return array<string, mixed>
     */
    private function attachIssueToProject(string $token, array $issue, array $project, ?string $statusOptionId): array
    {
        $issueId = trim((string) ($issue['id'] ?? ''));
        $projectId = trim((string) ($project['id'] ?? ''));

        if ($issueId === '' || $projectId === '') {
            throw new GithubGraphQLException('GitHub returned an invalid issue or project identifier.');
        }

        $attachResult = $this->graphqlClient->query($token, self::ADD_PROJECT_ITEM_MUTATION, [
            'projectId' => $projectId,
            'contentId' => $issueId,
        ]);

        $projectItem = $attachResult['addProjectV2ItemById']['item'] ?? null;
        if (!is_array($projectItem)) {
            throw new GithubGraphQLException('GitHub did not return the newly created project item.');
        }

        $projectState = [
            'projectId' => $projectId,
            'attached' => true,
            'statusUpdated' => false,
            'message' => null,
        ];

        if ($statusOptionId === null) {
            return $projectState;
        }

        $statusField = $project['statusField'] ?? null;
        if (!is_array($statusField)) {
            throw new GithubGraphQLException('GitHub project status field is not available.');
        }

        $itemId = trim((string) ($projectItem['id'] ?? ''));
        $fieldId = trim((string) ($statusField['id'] ?? ''));
        if ($itemId === '' || $fieldId === '') {
            throw new GithubGraphQLException('GitHub returned an invalid project status field payload.');
        }

        $this->graphqlClient->query($token, self::UPDATE_PROJECT_STATUS_MUTATION, [
            'projectId' => $projectId,
            'itemId' => $itemId,
            'fieldId' => $fieldId,
            'statusOptionId' => $statusOptionId,
        ]);

        $projectState['statusUpdated'] = true;

        return $projectState;
    }

    /**
     * @param array<string, mixed> $issue
     *
     * @return array<string, mixed>
     */
    private function normalizeIssue(array $issue): array
    {
        $assigneeNodes = $issue['assignees']['nodes'] ?? [];
        $labelNodes = $issue['labels']['nodes'] ?? [];

        return [
            'id' => (string) ($issue['id'] ?? ''),
            'number' => (int) ($issue['number'] ?? 0),
            'title' => (string) ($issue['title'] ?? ''),
            'body' => (string) ($issue['body'] ?? ''),
            'state' => (string) ($issue['state'] ?? 'OPEN'),
            'url' => (string) ($issue['url'] ?? ''),
            'createdAt' => (string) ($issue['createdAt'] ?? ''),
            'updatedAt' => (string) ($issue['updatedAt'] ?? $issue['createdAt'] ?? ''),
            'viewerCanUpdate' => (bool) ($issue['viewerCanUpdate'] ?? false),
            'viewerCanClose' => (bool) ($issue['viewerCanClose'] ?? false),
            'viewerCanReopen' => (bool) ($issue['viewerCanReopen'] ?? false),
            'authorLogin' => is_array($issue['author'] ?? null) ? ($issue['author']['login'] ?? null) : null,
            'assignees' => $this->normalizeGithubUsers($assigneeNodes),
            'labels' => is_array($labelNodes) ? array_values(array_filter($labelNodes, 'is_array')) : [],
            'repository' => [
                'nameWithOwner' => is_array($issue['repository'] ?? null) ? (string) (($issue['repository']['nameWithOwner'] ?? '')) : '',
                'url' => is_array($issue['repository'] ?? null) ? ($issue['repository']['url'] ?? null) : null,
            ],
        ];
    }

    /**
     * @param list<string> $assigneeIds
     * @param array<string, mixed> $repository
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

    /**
     * @param mixed $rawUsers
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
                'name' => $rawUser['name'] ?? null,
                'avatarUrl' => trim((string) ($rawUser['avatarUrl'] ?? '')),
                'url' => trim((string) ($rawUser['url'] ?? '')),
            ];
        }

        return $users;
    }
}
