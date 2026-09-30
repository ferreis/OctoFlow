<?php

namespace App\Github;

use App\Entity\User;
use App\Github\Exception\GithubActionForbiddenException;
use App\Github\Exception\GithubGraphQLException;

final class GithubIssueRepositoryScopeGuard
{
    private const ISSUE_REPOSITORY_QUERY = <<<'GRAPHQL'
query OctoFlowIssueRepositoryScope($issueId: ID!) {
  node(id: $issueId) {
    __typename
    ... on Issue {
      repository {
        nameWithOwner
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

    public function assertIssueAllowed(User $user, string $issueId): void
    {
        $normalizedIssueId = trim($issueId);
        if ($normalizedIssueId === '') {
            throw new \InvalidArgumentException('The GitHub issue id is required.');
        }

        $repositoryKey = $this->resolveCachedRepositoryKey($user, $normalizedIssueId)
            ?? $this->fetchRepositoryKey($user, $normalizedIssueId);

        $normalizedRepositoryKey = $this->normalizeRepositoryKey($repositoryKey);
        if ($normalizedRepositoryKey === '') {
            throw new GithubGraphQLException('GitHub returned an invalid repository context for the selected issue.');
        }

        foreach ($this->registryService->buildCatalog($user, false) as $registeredRepository) {
            $registeredRepositoryKey = $this->normalizeRepositoryKey($registeredRepository['nameWithOwner'] ?? null);
            if ($registeredRepositoryKey !== '' && hash_equals($registeredRepositoryKey, $normalizedRepositoryKey)) {
                return;
            }
        }

        throw new GithubActionForbiddenException(
            'The selected GitHub issue belongs to a repository that is not enabled for the authenticated user.'
        );
    }

    private function resolveCachedRepositoryKey(User $user, string $issueId): ?string
    {
        $cachedIssue = $this->cacheService->findCachedIssue($user, $issueId);
        if (!is_array($cachedIssue)) {
            return null;
        }

        $repository = $cachedIssue['repository'] ?? null;
        if (!is_array($repository)) {
            return null;
        }

        $repositoryKey = trim((string) ($repository['nameWithOwner'] ?? ''));

        return $repositoryKey !== '' ? $repositoryKey : null;
    }

    private function fetchRepositoryKey(User $user, string $issueId): string
    {
        $token = $this->profileService->requireToken($user);
        $data = $this->graphqlClient->query($token, self::ISSUE_REPOSITORY_QUERY, [
            'issueId' => $issueId,
        ]);

        $node = $data['node'] ?? null;
        if (!is_array($node) || trim((string) ($node['__typename'] ?? '')) !== 'Issue') {
            throw new GithubGraphQLException('GitHub did not return the selected issue repository context.');
        }

        $repository = $node['repository'] ?? null;
        if (!is_array($repository)) {
            throw new GithubGraphQLException('GitHub did not return the selected issue repository.');
        }

        return trim((string) ($repository['nameWithOwner'] ?? ''));
    }

    private function normalizeRepositoryKey(mixed $value): string
    {
        $normalized = trim((string) $value);
        if ($normalized === '') {
            return '';
        }

        return function_exists('mb_strtolower')
            ? mb_strtolower($normalized, 'UTF-8')
            : strtolower($normalized);
    }
}
