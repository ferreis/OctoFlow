<?php

namespace App\Github;

use App\Entity\User;
use App\Github\Exception\GithubGraphQLException;

final class GithubWorkspaceService
{
    private const REPOSITORY_QUERY = <<<'GRAPHQL'
query GithubRepositoryWorkspace($owner: String!, $name: String!) {
  repository(owner: $owner, name: $name) {
    id
    name
    nameWithOwner
    description
    url
    owner {
      login
    }
    labels(first: 50) {
      nodes {
        id
        name
        color
        description
      }
    }
  }
}
GRAPHQL;

    private const PROJECTS_QUERY = <<<'GRAPHQL'
query GithubProjects($login: String!) {
  organization(login: $login) {
    projectsV2(first: 20) {
      nodes {
        ...ProjectFields
      }
    }
  }
  user(login: $login) {
    projectsV2(first: 20) {
      nodes {
        ...ProjectFields
      }
    }
  }
}

fragment ProjectFields on ProjectV2 {
  id
  number
  title
  shortDescription
  url
  closed
  fields(first: 20) {
    nodes {
      __typename
      ... on ProjectV2FieldCommon {
        id
        name
      }
      ... on ProjectV2SingleSelectField {
        options {
          id
          name
          color
          description
        }
      }
    }
  }
}
GRAPHQL;

    public function __construct(
        private readonly GithubProfileService $profileService,
        private readonly GithubGraphQLClientInterface $graphqlClient,
        private readonly GithubIssueTemplateCatalog $templateCatalog,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function fetchWorkspace(User $user, ?string $repositoryOwner = null, ?string $repositoryName = null): array
    {
        $repository = $this->fetchRepository($user, $repositoryOwner, $repositoryName);
        $projects = [];
        $projectsMeta = [
            'available' => true,
            'message' => null,
        ];

        try {
            $projects = $this->fetchProjectsForRepositoryOwner($user, $repository['ownerLogin']);
        } catch (GithubGraphQLException $exception) {
            $projectsMeta = [
                'available' => false,
                'message' => $exception->getMessage(),
            ];
        }

        return [
            'repository' => $repository,
            'templates' => $this->templateCatalog->all(),
            'projects' => $projects,
            'projectsMeta' => $projectsMeta,
            'profile' => $this->profileService->buildProfilePayload($user),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function fetchRepository(User $user, ?string $repositoryOwner = null, ?string $repositoryName = null): array
    {
        $runtimeConfiguration = $this->profileService->buildRuntimeConfiguration($user, $repositoryOwner, $repositoryName);

        $data = $this->graphqlClient->query($runtimeConfiguration->token, self::REPOSITORY_QUERY, [
            'owner' => $runtimeConfiguration->repositoryOwner,
            'name' => $runtimeConfiguration->repositoryName,
        ]);

        $repository = $data['repository'] ?? null;
        if (!is_array($repository)) {
            throw new GithubGraphQLException('GitHub could not find the configured repository.');
        }

        $owner = $repository['owner'] ?? null;
        if (!is_array($owner) || !is_string($owner['login'] ?? null) || trim($owner['login']) === '') {
            throw new GithubGraphQLException('GitHub returned an invalid repository owner.');
        }

        return [
            'id' => (string) ($repository['id'] ?? ''),
            'name' => (string) ($repository['name'] ?? ''),
            'nameWithOwner' => (string) ($repository['nameWithOwner'] ?? ''),
            'description' => $this->normalizeNullableString($repository['description'] ?? null),
            'url' => (string) ($repository['url'] ?? ''),
            'ownerLogin' => trim((string) $owner['login']),
            'labels' => $this->normalizeLabels($repository['labels']['nodes'] ?? []),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function fetchProjectsForRepositoryOwner(User $user, ?string $ownerLogin = null): array
    {
        $runtimeConfiguration = $this->profileService->buildRuntimeConfiguration($user);

        $login = trim((string) $ownerLogin);
        if ($login === '') {
            $repository = $this->fetchRepository($user);
            $login = trim((string) ($repository['ownerLogin'] ?? ''));
        }

        if ($login === '') {
            throw new GithubGraphQLException('GitHub returned an invalid repository owner login for projects.');
        }

        $data = $this->graphqlClient->query($runtimeConfiguration->token, self::PROJECTS_QUERY, [
            'login' => $login,
        ]);

        $owner = $data['organization'] ?? $data['user'] ?? null;
        if (!is_array($owner)) {
            return [];
        }

        $projectsConnection = $owner['projectsV2'] ?? null;
        $projectNodes = is_array($projectsConnection) ? ($projectsConnection['nodes'] ?? []) : [];

        return $this->normalizeProjects($projectNodes);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findProjectById(User $user, string $projectId): ?array
    {
        $normalizedProjectId = trim($projectId);
        if ($normalizedProjectId === '') {
            return null;
        }

        foreach ($this->fetchProjectsForRepositoryOwner($user) as $project) {
            if (($project['id'] ?? null) === $normalizedProjectId) {
                return $project;
            }
        }

        return null;
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

    /**
     * @param mixed $rawProjects
     *
     * @return list<array<string, mixed>>
     */
    private function normalizeProjects(mixed $rawProjects): array
    {
        if (!is_array($rawProjects)) {
            return [];
        }

        $projects = [];

        foreach ($rawProjects as $rawProject) {
            if (!is_array($rawProject) || (bool) ($rawProject['closed'] ?? false)) {
                continue;
            }

            $projectId = trim((string) ($rawProject['id'] ?? ''));
            $projectTitle = trim((string) ($rawProject['title'] ?? ''));
            if ($projectId === '' || $projectTitle === '') {
                continue;
            }

            $statusField = $this->extractStatusField($rawProject['fields']['nodes'] ?? []);

            $projects[] = [
                'id' => $projectId,
                'number' => (int) ($rawProject['number'] ?? 0),
                'title' => $projectTitle,
                'shortDescription' => $this->normalizeNullableString($rawProject['shortDescription'] ?? null),
                'url' => trim((string) ($rawProject['url'] ?? '')),
                'statusField' => $statusField,
            ];
        }

        return $projects;
    }

    /**
     * @param mixed $rawFields
     *
     * @return array<string, mixed>|null
     */
    private function extractStatusField(mixed $rawFields): ?array
    {
        if (!is_array($rawFields)) {
            return null;
        }

        foreach ($rawFields as $rawField) {
            if (!is_array($rawField)) {
                continue;
            }

            $fieldType = trim((string) ($rawField['__typename'] ?? ''));
            $fieldName = trim((string) ($rawField['name'] ?? ''));

            if ($fieldType !== 'ProjectV2SingleSelectField' || mb_strtolower($fieldName) !== 'status') {
                continue;
            }

            $fieldId = trim((string) ($rawField['id'] ?? ''));
            if ($fieldId === '') {
                continue;
            }

            return [
                'id' => $fieldId,
                'name' => $fieldName,
                'options' => $this->normalizeProjectStatusOptions($rawField['options'] ?? []),
            ];
        }

        return null;
    }

    /**
     * @param mixed $rawOptions
     *
     * @return list<array<string, mixed>>
     */
    private function normalizeProjectStatusOptions(mixed $rawOptions): array
    {
        if (!is_array($rawOptions)) {
            return [];
        }

        $options = [];

        foreach ($rawOptions as $rawOption) {
            if (!is_array($rawOption)) {
                continue;
            }

            $optionId = trim((string) ($rawOption['id'] ?? ''));
            $optionName = trim((string) ($rawOption['name'] ?? ''));
            if ($optionId === '' || $optionName === '') {
                continue;
            }

            $options[] = [
                'id' => $optionId,
                'name' => $optionName,
                'color' => $this->normalizeNullableString($rawOption['color'] ?? null),
                'description' => $this->normalizeNullableString($rawOption['description'] ?? null),
            ];
        }

        return $options;
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalizedValue = trim((string) $value);

        return $normalizedValue === '' ? null : $normalizedValue;
    }
}
