<?php

namespace App\Github;

use App\Entity\User;
use App\Github\Exception\GithubGraphQLException;

final class GithubIssueService
{
    private const CREATE_ISSUE_MUTATION = <<<'GRAPHQL'
mutation CreateIssue($repositoryId: ID!, $title: String!, $body: String!, $labelIds: [ID!]) {
  createIssue(input: {repositoryId: $repositoryId, title: $title, body: $body, labelIds: $labelIds}) {
    issue {
      id
      number
      title
      url
      createdAt
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
        private readonly GithubIssueBodyRenderer $bodyRenderer,
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

        $runtimeConfiguration = $this->profileService->buildRuntimeConfiguration($user);
        $repository = $this->workspaceService->fetchRepository($user);
        $projectSelection = $this->normalizeProjectSelection($payload);
        $validatedProject = $this->validateProjectSelection(
            $user,
            (string) ($repository['ownerLogin'] ?? ''),
            $projectSelection['projectId'],
            $projectSelection['statusOptionId']
        );

        $issueData = $this->graphqlClient->query($runtimeConfiguration->token, self::CREATE_ISSUE_MUTATION, [
            'repositoryId' => (string) ($repository['id'] ?? ''),
            'title' => $draft['title'],
            'body' => $draft['body'],
            'labelIds' => $this->normalizeIdList($payload['labelIds'] ?? []),
        ]);

        $issue = $issueData['createIssue']['issue'] ?? null;
        if (!is_array($issue)) {
            throw new GithubGraphQLException('GitHub did not return the created issue payload.');
        }

        $projectResult = [
            'projectId' => $projectSelection['projectId'],
            'attached' => false,
            'statusUpdated' => false,
            'message' => null,
        ];

        if ($validatedProject !== null) {
            try {
                $projectResult = $this->attachIssueToProject(
                    $runtimeConfiguration->token,
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
            'project' => $projectResult,
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
}
