<?php

namespace App\Github;

use App\Entity\GithubCachedIssue;
use App\Entity\GithubIssueSyncState;
use App\Entity\User;
use App\Repository\GithubCachedIssueRepository;
use App\Repository\GithubIssueSyncStateRepository;
use Doctrine\ORM\EntityManagerInterface;

class GithubIssueCacheService
{
    public const CACHE_TTL_SECONDS = 300;
    public const ISSUE_SCOPE_ALL = 'all';
    public const ISSUE_SCOPE_ASSIGNED = 'assigned';
    public const ISSUE_SCOPE_REPOSITORY = 'repository';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly GithubCachedIssueRepository $cachedIssueRepository,
        private readonly GithubIssueSyncStateRepository $syncStateRepository,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function buildCachedBoard(
        User $user,
        string $scope = self::ISSUE_SCOPE_ALL,
        ?string $repositoryOwner = null,
        ?string $repositoryName = null,
    ): array {
        $normalizedScope = $this->normalizeScope($scope);
        $repositorySelection = $this->resolveRepositorySelection($user, $normalizedScope, $repositoryOwner, $repositoryName);
        $syncState = $this->syncStateRepository->findOneByOwnerScope($user, $normalizedScope, $repositorySelection['stateKey']);

        $issues = match ($normalizedScope) {
            self::ISSUE_SCOPE_ASSIGNED => $this->cachedIssueRepository->findAssignedActiveByOwner($user),
            self::ISSUE_SCOPE_REPOSITORY => $repositorySelection['repositoryKey'] !== ''
                ? $this->cachedIssueRepository->findActiveByOwnerAndRepositoryKey($user, $repositorySelection['repositoryKey'])
                : [],
            default => $this->cachedIssueRepository->findActiveByOwner($user),
        };

        $items = array_map(fn (GithubCachedIssue $issue): array => $this->normalizeCachedIssue($issue), $issues);
        $repositoryCatalog = $this->normalizeRepositoryCatalog($syncState?->getRepositoryCatalog() ?? []);

        if ($repositoryCatalog === []) {
            $repositoryCatalog = $this->deriveRepositoryCatalog($items);
        }

        if (
            $normalizedScope === self::ISSUE_SCOPE_REPOSITORY
            && $repositorySelection['repositoryKey'] !== ''
            && !$this->hasRepositoryInCatalog($repositoryCatalog, $repositorySelection['repositoryKey'])
        ) {
            array_unshift($repositoryCatalog, $this->buildRepositorySummary(
                $repositorySelection['repositoryOwner'],
                $repositorySelection['repositoryName'],
                $repositorySelection['repositoryKey']
            ));
        }

        return [
            'scope' => $normalizedScope,
            'viewer' => [
                'login' => $syncState?->getViewerLogin(),
            ],
            'repository' => $normalizedScope === self::ISSUE_SCOPE_REPOSITORY
                ? $this->findRepositoryInCatalog($repositoryCatalog, $repositorySelection['repositoryKey'])
                : null,
            'repositories' => $repositoryCatalog,
            'items' => $items,
            'cache' => [
                'source' => 'database',
                'available' => $syncState !== null || $items !== [],
                'lastSyncedAt' => $syncState?->getSyncedAt()->format(DATE_ATOM),
                'needsRefresh' => $this->needsRefresh($syncState),
                'ttlSeconds' => self::CACHE_TTL_SECONDS,
            ],
        ];
    }

    /**
     * @param list<array<string, mixed>> $issues
     * @param list<array<string, mixed>> $repositoryCatalog
     */
    public function syncIssues(
        User $user,
        string $scope,
        array $issues,
        string $viewerLogin,
        array $repositoryCatalog,
        ?string $repositoryOwner = null,
        ?string $repositoryName = null,
    ): void {
        $normalizedScope = $this->normalizeScope($scope);
        $repositorySelection = $this->resolveRepositorySelection($user, $normalizedScope, $repositoryOwner, $repositoryName);

        if ($normalizedScope === self::ISSUE_SCOPE_ALL) {
            $this->cachedIssueRepository->markAllInactiveForOwner($user);
        } elseif ($normalizedScope === self::ISSUE_SCOPE_REPOSITORY && $repositorySelection['repositoryKey'] !== '') {
            $this->cachedIssueRepository->markRepositoryInactiveForOwner($user, $repositorySelection['repositoryKey']);
        } elseif ($normalizedScope === self::ISSUE_SCOPE_ASSIGNED) {
            $this->cachedIssueRepository->clearAssignedFlagForOwner($user);
        }

        foreach ($issues as $issue) {
            $this->storeIssue($user, $issue, $viewerLogin);
        }

        $syncState = $this->syncStateRepository->findOneByOwnerScope($user, $normalizedScope, $repositorySelection['stateKey']);
        if ($syncState === null) {
            $syncState = (new GithubIssueSyncState())
                ->setOwner($user)
                ->setScope($normalizedScope)
                ->setRepositoryKey($repositorySelection['stateKey']);

            $this->entityManager->persist($syncState);
        }

        $syncState
            ->setViewerLogin($viewerLogin)
            ->setRepositoryCatalog($this->normalizeRepositoryCatalog($repositoryCatalog))
            ->setSyncedAt(new \DateTimeImmutable());

        $this->entityManager->flush();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findCachedIssue(User $user, string $githubIssueId): ?array
    {
        $issue = $this->cachedIssueRepository->findOneByOwnerAndGithubIssueId($user, $githubIssueId);

        return $issue instanceof GithubCachedIssue ? $this->normalizeCachedIssue($issue) : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function upsertIssue(User $user, array $issue, ?string $viewerLogin = null): array
    {
        $cachedIssue = $this->storeIssue($user, $issue, $viewerLogin);
        $this->entityManager->flush();

        return $this->normalizeCachedIssue($cachedIssue);
    }

    public function normalizeScope(string $scope): string
    {
        $normalizedScope = trim(strtolower($scope));

        if ($normalizedScope === '') {
            return self::ISSUE_SCOPE_ALL;
        }

        if (!in_array($normalizedScope, [self::ISSUE_SCOPE_ALL, self::ISSUE_SCOPE_ASSIGNED, self::ISSUE_SCOPE_REPOSITORY], true)) {
            throw new \InvalidArgumentException('The GitHub issue scope must be "all", "assigned" or "repository".');
        }

        return $normalizedScope;
    }

    private function needsRefresh(?GithubIssueSyncState $syncState): bool
    {
        if ($syncState === null) {
            return true;
        }

        return $syncState->getSyncedAt() < new \DateTimeImmutable(sprintf('-%d seconds', self::CACHE_TTL_SECONDS));
    }

    /**
     * @return array{repositoryOwner: string, repositoryName: string, repositoryKey: string, stateKey: string}
     */
    private function resolveRepositorySelection(
        User $user,
        string $scope,
        ?string $repositoryOwner = null,
        ?string $repositoryName = null,
    ): array {
        $resolvedRepositoryOwner = trim($repositoryOwner ?? '');
        $resolvedRepositoryName = trim($repositoryName ?? '');

        if ($scope === self::ISSUE_SCOPE_REPOSITORY && ($resolvedRepositoryOwner === '' || $resolvedRepositoryName === '')) {
            $resolvedRepositoryOwner = trim((string) $user->getGithubRepositoryOwner());
            $resolvedRepositoryName = trim((string) $user->getGithubRepositoryName());
        }

        $repositoryKey = ($resolvedRepositoryOwner !== '' && $resolvedRepositoryName !== '')
            ? sprintf('%s/%s', $resolvedRepositoryOwner, $resolvedRepositoryName)
            : '';

        return [
            'repositoryOwner' => $resolvedRepositoryOwner,
            'repositoryName' => $resolvedRepositoryName,
            'repositoryKey' => $repositoryKey,
            'stateKey' => $scope === self::ISSUE_SCOPE_REPOSITORY ? $repositoryKey : '',
        ];
    }

    private function storeIssue(User $user, array $issue, ?string $viewerLogin = null): GithubCachedIssue
    {
        $githubIssueId = trim((string) ($issue['id'] ?? ''));
        if ($githubIssueId === '') {
            throw new \InvalidArgumentException('The GitHub issue id is required for caching.');
        }

        $cachedIssue = $this->cachedIssueRepository->findOneByOwnerAndGithubIssueId($user, $githubIssueId);
        if ($cachedIssue === null) {
            $cachedIssue = (new GithubCachedIssue())
                ->setOwner($user)
                ->setGithubIssueId($githubIssueId);

            $this->entityManager->persist($cachedIssue);
        }

        $repository = $issue['repository'] ?? null;
        $repositoryKey = trim((string) (is_array($repository) ? ($repository['nameWithOwner'] ?? '') : ''));
        $repositoryParts = $this->splitRepositoryKey($repositoryKey);
        $assignees = $this->normalizeArrayCollection($issue['assignees'] ?? []);
        $labels = $this->normalizeArrayCollection($issue['labels'] ?? []);

        $assignedToViewer = $cachedIssue->isAssignedToViewer();
        if ($viewerLogin !== null && trim($viewerLogin) !== '') {
            $assignedToViewer = $this->isIssueAssignedToViewer($assignees, $viewerLogin);
        }

        $cachedIssue
            ->setIssueNumber((int) ($issue['number'] ?? 0))
            ->setRepositoryOwner($repositoryParts['owner'])
            ->setRepositoryName($repositoryParts['name'])
            ->setRepositoryKey($repositoryKey)
            ->setRepositoryUrl(is_array($repository) ? ($repository['url'] ?? null) : null)
            ->setTitle((string) ($issue['title'] ?? ''))
            ->setBody((string) ($issue['body'] ?? ''))
            ->setState((string) ($issue['state'] ?? 'OPEN'))
            ->setUrl((string) ($issue['url'] ?? ''))
            ->setAuthorLogin($issue['authorLogin'] ?? null)
            ->setViewerCanUpdate((bool) ($issue['viewerCanUpdate'] ?? false))
            ->setViewerCanClose((bool) ($issue['viewerCanClose'] ?? false))
            ->setViewerCanReopen((bool) ($issue['viewerCanReopen'] ?? false))
            ->setAssignedToViewer($assignedToViewer)
            ->setActive(true)
            ->setAssignees($assignees)
            ->setLabels($labels)
            ->setGithubCreatedAt($this->parseDateTime($issue['createdAt'] ?? null))
            ->setGithubUpdatedAt($this->parseDateTime($issue['updatedAt'] ?? null))
            ->setCachedAt(new \DateTimeImmutable());

        return $cachedIssue;
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeCachedIssue(GithubCachedIssue $issue): array
    {
        return [
            'id' => $issue->getGithubIssueId(),
            'number' => $issue->getIssueNumber(),
            'title' => $issue->getTitle(),
            'body' => $issue->getBody(),
            'state' => $issue->getState(),
            'url' => $issue->getUrl(),
            'createdAt' => $issue->getGithubCreatedAt()->format(DATE_ATOM),
            'updatedAt' => $issue->getGithubUpdatedAt()->format(DATE_ATOM),
            'viewerCanUpdate' => $issue->isViewerCanUpdate(),
            'viewerCanClose' => $issue->isViewerCanClose(),
            'viewerCanReopen' => $issue->isViewerCanReopen(),
            'authorLogin' => $issue->getAuthorLogin(),
            'assignees' => $issue->getAssignees(),
            'labels' => $issue->getLabels(),
            'repository' => [
                'ownerLogin' => $issue->getRepositoryOwner(),
                'name' => $issue->getRepositoryName(),
                'nameWithOwner' => $issue->getRepositoryKey(),
                'url' => $issue->getRepositoryUrl() ?? '',
            ],
            'cache' => [
                'cachedAt' => $issue->getCachedAt()->format(DATE_ATOM),
            ],
        ];
    }

    /**
     * @param mixed $rawCatalog
     *
     * @return list<array<string, mixed>>
     */
    private function normalizeRepositoryCatalog(mixed $rawCatalog): array
    {
        if (!is_array($rawCatalog)) {
            return [];
        }

        $catalog = [];

        foreach ($rawCatalog as $rawRepository) {
            if (!is_array($rawRepository)) {
                continue;
            }

            $repositoryKey = trim((string) ($rawRepository['nameWithOwner'] ?? ''));
            if ($repositoryKey === '') {
                continue;
            }

            $parts = $this->splitRepositoryKey($repositoryKey);
            if ($parts['owner'] === '' || $parts['name'] === '') {
                continue;
            }

            if (array_key_exists($repositoryKey, $catalog)) {
                continue;
            }

            $catalog[$repositoryKey] = [
                'ownerLogin' => $parts['owner'],
                'name' => $parts['name'],
                'nameWithOwner' => $repositoryKey,
                'url' => trim((string) ($rawRepository['url'] ?? '')),
            ];
        }

        return array_values($catalog);
    }

    /**
     * @param list<array<string, mixed>> $items
     *
     * @return list<array<string, mixed>>
     */
    private function deriveRepositoryCatalog(array $items): array
    {
        $catalog = [];

        foreach ($items as $item) {
            $repository = $item['repository'] ?? null;
            if (!is_array($repository)) {
                continue;
            }

            $repositoryKey = trim((string) ($repository['nameWithOwner'] ?? ''));
            if ($repositoryKey === '' || array_key_exists($repositoryKey, $catalog)) {
                continue;
            }

            $catalog[$repositoryKey] = [
                'ownerLogin' => trim((string) ($repository['ownerLogin'] ?? '')),
                'name' => trim((string) ($repository['name'] ?? '')),
                'nameWithOwner' => $repositoryKey,
                'url' => trim((string) ($repository['url'] ?? '')),
            ];
        }

        return array_values($catalog);
    }

    /**
     * @param list<array<string, mixed>> $catalog
     *
     * @return array<string, mixed>|null
     */
    private function findRepositoryInCatalog(array $catalog, string $repositoryKey): ?array
    {
        foreach ($catalog as $repository) {
            if (($repository['nameWithOwner'] ?? null) === $repositoryKey) {
                return $repository;
            }
        }

        return null;
    }

    /**
     * @param list<array<string, mixed>> $catalog
     */
    private function hasRepositoryInCatalog(array $catalog, string $repositoryKey): bool
    {
        return $this->findRepositoryInCatalog($catalog, $repositoryKey) !== null;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildRepositorySummary(string $repositoryOwner, string $repositoryName, string $repositoryKey): array
    {
        return [
            'ownerLogin' => $repositoryOwner,
            'name' => $repositoryName,
            'nameWithOwner' => $repositoryKey,
            'url' => '',
        ];
    }

    /**
     * @return array{owner: string, name: string}
     */
    private function splitRepositoryKey(string $repositoryKey): array
    {
        $normalizedKey = trim($repositoryKey);
        $separatorIndex = strpos($normalizedKey, '/');

        if ($separatorIndex === false || $separatorIndex <= 0) {
            return ['owner' => '', 'name' => ''];
        }

        return [
            'owner' => trim(substr($normalizedKey, 0, $separatorIndex)),
            'name' => trim(substr($normalizedKey, $separatorIndex + 1)),
        ];
    }

    /**
     * @param mixed $rawCollection
     *
     * @return array<int, array<string, mixed>>
     */
    private function normalizeArrayCollection(mixed $rawCollection): array
    {
        if (!is_array($rawCollection)) {
            return [];
        }

        $collection = [];

        foreach ($rawCollection as $entry) {
            if (is_array($entry)) {
                $collection[] = $entry;
            }
        }

        return array_values($collection);
    }

    /**
     * @param array<int, array<string, mixed>> $assignees
     */
    private function isIssueAssignedToViewer(array $assignees, string $viewerLogin): bool
    {
        $normalizedViewerLogin = trim(strtolower($viewerLogin));
        if ($normalizedViewerLogin === '') {
            return false;
        }

        foreach ($assignees as $assignee) {
            $candidateLogin = trim(strtolower((string) ($assignee['login'] ?? '')));
            if ($candidateLogin === $normalizedViewerLogin) {
                return true;
            }
        }

        return false;
    }

    private function parseDateTime(mixed $value): \DateTimeImmutable
    {
        $normalizedValue = trim((string) $value);
        if ($normalizedValue === '') {
            return new \DateTimeImmutable();
        }

        try {
            return new \DateTimeImmutable($normalizedValue);
        } catch (\Exception) {
            return new \DateTimeImmutable();
        }
    }
}
