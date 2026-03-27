<?php

namespace App\Task;

use App\Entity\LocalTask;
use App\Entity\User;
use App\Github\Exception\GithubConfigurationException;
use App\Github\Exception\GithubGraphQLException;
use App\Github\GithubIssuePublisherInterface;
use App\Github\GithubIssueTemplateCatalog;
use App\Github\TemplateAccessService;
use App\Repository\LocalTaskRepository;
use Doctrine\ORM\EntityManagerInterface;

final class LocalTaskService implements LocalTaskSyncInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LocalTaskRepository $localTaskRepository,
        private readonly GithubIssuePublisherInterface $issuePublisher,
        private readonly GithubIssueTemplateCatalog $templateCatalog,
        private readonly TemplateAccessService $templateAccessService,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function buildBoard(User $user): array
    {
        $items = array_map(
            fn (LocalTask $task): array => $this->normalizeTask($task),
            $this->localTaskRepository->findUnsyncedByOwner($user),
        );

        $pendingCount = count(array_filter(
            $items,
            static fn (array $task): bool => ($task['syncState'] ?? null) === LocalTask::SYNC_STATE_PENDING,
        ));
        $failedCount = count(array_filter(
            $items,
            static fn (array $task): bool => ($task['syncState'] ?? null) === LocalTask::SYNC_STATE_FAILED,
        ));

        return [
            'items' => $items,
            'stats' => [
                'total' => count($items),
                'pending' => $pendingCount,
                'failed' => $failedCount,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function createTask(User $user, array $payload): array
    {
        $title = trim((string) ($payload['title'] ?? ''));
        $body = trim((string) ($payload['body'] ?? ''));
        $templateKey = trim((string) ($payload['templateKey'] ?? ''));
        $repositoryOwner = trim((string) ($payload['repositoryOwner'] ?? ''));
        $repositoryName = trim((string) ($payload['repositoryName'] ?? ''));
        $labelNames = $this->normalizeLabelNames($payload['labelNames'] ?? []);

        if ($title === '') {
            throw new \InvalidArgumentException('The local task title is required.');
        }

        if ($body === '') {
            throw new \InvalidArgumentException('The local task body is required.');
        }

        if (($repositoryOwner === '') xor ($repositoryName === '')) {
            throw new \InvalidArgumentException('Inform the synchronization repository owner and name together.');
        }

        if ($templateKey !== '') {
            $template = $this->templateCatalog->find($templateKey);
            if ($template === null) {
                throw new \InvalidArgumentException('Unknown task template.');
            }

            if (!$this->templateAccessService->canUseTemplate($user, $template)) {
                throw new \InvalidArgumentException('The selected task template is not available for your profile.');
            }
        }

        $task = (new LocalTask())
            ->setOwner($user)
            ->setTemplateKey($templateKey !== '' ? $templateKey : null)
            ->setTitle($title)
            ->setBody($body)
            ->setLabelNames($labelNames)
            ->setRepositoryOwner($repositoryOwner !== '' ? $repositoryOwner : null)
            ->setRepositoryName($repositoryName !== '' ? $repositoryName : null)
            ->markPending();

        $this->entityManager->persist($task);
        $this->entityManager->flush();

        return $this->normalizeTask($task);
    }

    /**
     * @return array<string, mixed>
     */
    public function getTask(User $user, int $taskId): array
    {
        return $this->normalizeTask($this->requireTask($user, $taskId));
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function updateTask(User $user, int $taskId, array $payload): array
    {
        $task = $this->requireTask($user, $taskId);
        if ($task->getSyncState() === LocalTask::SYNC_STATE_SYNCED) {
            throw new \InvalidArgumentException('This local task has already been synchronized with GitHub.');
        }

        $previousLabelNames = $task->getLabelNames();
        $previousState = $task->getState();
        $title = trim((string) ($payload['title'] ?? ''));
        $body = trim((string) ($payload['body'] ?? ''));
        $templateKey = trim((string) ($payload['templateKey'] ?? ''));
        $state = strtoupper(trim((string) ($payload['state'] ?? LocalTask::STATE_OPEN)));
        $repositoryOwner = trim((string) ($payload['repositoryOwner'] ?? ''));
        $repositoryName = trim((string) ($payload['repositoryName'] ?? ''));
        $labelNames = $this->normalizeLabelNames($payload['labelNames'] ?? []);

        if ($title === '') {
            throw new \InvalidArgumentException('The local task title is required.');
        }

        if ($body === '') {
            throw new \InvalidArgumentException('The local task body is required.');
        }

        if (($repositoryOwner === '') xor ($repositoryName === '')) {
            throw new \InvalidArgumentException('Inform the synchronization repository owner and name together.');
        }

        if (!in_array($state, [LocalTask::STATE_OPEN, LocalTask::STATE_CLOSED], true)) {
            throw new \InvalidArgumentException('The local task state must be OPEN or CLOSED.');
        }

        if ($templateKey !== '') {
            $template = $this->templateCatalog->find($templateKey);
            if ($template === null) {
                throw new \InvalidArgumentException('Unknown task template.');
            }

            if (!$this->templateAccessService->canUseTemplate($user, $template)) {
                throw new \InvalidArgumentException('The selected task template is not available for your profile.');
            }
        }

        $task
            ->setTemplateKey($templateKey !== '' ? $templateKey : null)
            ->setTitle($title)
            ->setBody($body)
            ->setState($state)
            ->setLabelNames($labelNames)
            ->setRepositoryOwner($repositoryOwner !== '' ? $repositoryOwner : null)
            ->setRepositoryName($repositoryName !== '' ? $repositoryName : null)
            ->markPending();

        $this->appendLabelHistoryEntries($task, $previousLabelNames, $labelNames);
        $this->appendStateHistoryEntry($task, $previousState, $state);
        $this->entityManager->flush();

        return $this->normalizeTask($task);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array{item: array<string, mixed>, github: array<string, mixed>}
     */
    public function syncTaskToGithub(User $user, int $taskId, array $payload): array
    {
        $task = $this->requireTask($user, $taskId);
        if ($task->getSyncState() === LocalTask::SYNC_STATE_SYNCED) {
            throw new \InvalidArgumentException('This local task has already been synchronized with GitHub.');
        }

        $repositoryOwner = trim((string) ($payload['repositoryOwner'] ?? ''));
        $repositoryName = trim((string) ($payload['repositoryName'] ?? ''));
        if ($repositoryOwner === '' || $repositoryName === '') {
            throw new \InvalidArgumentException('Select the GitHub repository before synchronizing this local task.');
        }

        $task
            ->setRepositoryOwner($repositoryOwner)
            ->setRepositoryName($repositoryName)
            ->markPending();

        try {
            $result = $this->issuePublisher->createDraftIssue($user, [
                'title' => $task->getTitle(),
                'body' => $task->getBody(),
                'newLabelNames' => $task->getLabelNames(),
                'repositoryOwner' => $repositoryOwner,
                'repositoryName' => $repositoryName,
            ]);

            $task->markSynced($result['issue'] ?? []);
            $this->entityManager->flush();

            return [
                'item' => $this->normalizeTask($task),
                'github' => $result,
            ];
        } catch (GithubConfigurationException | GithubGraphQLException | \InvalidArgumentException $exception) {
            $task->markFailed($exception->getMessage());
            $this->entityManager->flush();

            throw $exception;
        }
    }

    /**
     * @return array{synced: int, failed: int, skipped: int}
     */
    public function syncPendingTasks(User $user): array
    {
        $syncableTasks = $this->localTaskRepository->findSyncableByOwner($user);
        if ($syncableTasks === []) {
            return [
                'synced' => 0,
                'failed' => 0,
                'skipped' => 0,
            ];
        }

        if (!$user->hasGithubTokenConfigured()) {
            return [
                'synced' => 0,
                'failed' => 0,
                'skipped' => count($syncableTasks),
            ];
        }

        $synced = 0;
        $failed = 0;

        foreach ($syncableTasks as $task) {
            try {
                $result = $this->issuePublisher->createDraftIssue($user, [
                    'title' => $task->getTitle(),
                    'body' => $task->getBody(),
                    'newLabelNames' => $task->getLabelNames(),
                    'repositoryOwner' => $task->getRepositoryOwner(),
                    'repositoryName' => $task->getRepositoryName(),
                ]);

                $task->markSynced($result['issue'] ?? []);
                ++$synced;
            } catch (GithubConfigurationException | GithubGraphQLException | \InvalidArgumentException $exception) {
                $task->markFailed($exception->getMessage());
                ++$failed;
            }
        }

        $this->entityManager->flush();

        return [
            'synced' => $synced,
            'failed' => $failed,
            'skipped' => 0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeTask(LocalTask $task): array
    {
        $repositoryKey = $task->getRepositoryKey();

        return [
            'id' => $task->getId(),
            'templateKey' => $task->getTemplateKey(),
            'title' => $task->getTitle(),
            'body' => $task->getBody(),
            'state' => $task->getState(),
            'syncState' => $task->getSyncState(),
            'syncError' => $task->getSyncError(),
            'repositoryOwner' => $task->getRepositoryOwner(),
            'repositoryName' => $task->getRepositoryName(),
            'repositoryKey' => $repositoryKey,
            'labelNames' => $task->getLabelNames(),
            'historyEntries' => $task->getHistoryEntries(),
            'githubIssueId' => $task->getGithubIssueId(),
            'githubIssueNumber' => $task->getGithubIssueNumber(),
            'githubIssueUrl' => $task->getGithubIssueUrl(),
            'createdAt' => $task->getCreatedAt()->format(DATE_ATOM),
            'updatedAt' => $task->getUpdatedAt()->format(DATE_ATOM),
            'syncedAt' => $task->getSyncedAt()?->format(DATE_ATOM),
        ];
    }

    /**
     * @param list<string> $previousLabelNames
     * @param list<string> $nextLabelNames
     */
    private function appendLabelHistoryEntries(LocalTask $task, array $previousLabelNames, array $nextLabelNames): void
    {
        $normalizedPreviousLabelMap = [];
        foreach ($this->normalizeLabelNames($previousLabelNames) as $previousLabelName) {
            $normalizedPreviousLabelMap[strtolower($previousLabelName)] = $previousLabelName;
        }

        $normalizedNextLabelMap = [];
        foreach ($this->normalizeLabelNames($nextLabelNames) as $nextLabelName) {
            $normalizedNextLabelMap[strtolower($nextLabelName)] = $nextLabelName;
        }

        $addedLabelNames = [];
        foreach ($normalizedNextLabelMap as $normalizedKey => $normalizedLabelName) {
            if (!array_key_exists($normalizedKey, $normalizedPreviousLabelMap)) {
                $addedLabelNames[] = $normalizedLabelName;
            }
        }

        $removedLabelNames = [];
        foreach ($normalizedPreviousLabelMap as $normalizedKey => $normalizedLabelName) {
            if (!array_key_exists($normalizedKey, $normalizedNextLabelMap)) {
                $removedLabelNames[] = $normalizedLabelName;
            }
        }

        if ($addedLabelNames !== []) {
            $addedLabelList = implode(', ', $addedLabelNames);
            $task->appendHistoryEntry(
                'label-added',
                count($addedLabelNames) === 1 ? 'Tag adicionada' : 'Tags adicionadas',
                count($addedLabelNames) === 1
                    ? sprintf('Tag adicionada: %s.', $addedLabelList)
                    : sprintf('Tags adicionadas: %s.', $addedLabelList)
            );
        }

        if ($removedLabelNames !== []) {
            $removedLabelList = implode(', ', $removedLabelNames);
            $task->appendHistoryEntry(
                'label-removed',
                count($removedLabelNames) === 1 ? 'Tag removida' : 'Tags removidas',
                count($removedLabelNames) === 1
                    ? sprintf('Tag removida: %s.', $removedLabelList)
                    : sprintf('Tags removidas: %s.', $removedLabelList)
            );
        }
    }

    private function appendStateHistoryEntry(LocalTask $task, string $previousState, string $nextState): void
    {
        $normalizedPreviousState = strtoupper(trim($previousState));
        $normalizedNextState = strtoupper(trim($nextState));

        if ($normalizedPreviousState === $normalizedNextState) {
            return;
        }

        if ($normalizedNextState === LocalTask::STATE_CLOSED) {
            $task->appendHistoryEntry(
                'closed',
                'Tarefa local fechada',
                'A tarefa local foi marcada como fechada.'
            );

            return;
        }

        $task->appendHistoryEntry(
            'reopened',
            'Tarefa local reaberta',
            'A tarefa local voltou para o estado aberto.'
        );
    }

    /**
     * @return list<string>
     */
    private function normalizeLabelNames(mixed $rawLabelNames): array
    {
        if ($rawLabelNames === null) {
            return [];
        }

        if (!is_array($rawLabelNames)) {
            throw new \InvalidArgumentException('The local task labels payload is invalid.');
        }

        $normalizedLabelNames = [];
        $seenLabelKeys = [];

        foreach ($rawLabelNames as $rawLabelName) {
            $normalizedLabelName = preg_replace('/\s+/', ' ', trim((string) $rawLabelName));
            if (!is_string($normalizedLabelName) || $normalizedLabelName === '') {
                continue;
            }

            $normalizedLabelKey = strtolower($normalizedLabelName);
            if (isset($seenLabelKeys[$normalizedLabelKey])) {
                continue;
            }

            $seenLabelKeys[$normalizedLabelKey] = true;
            $normalizedLabelNames[] = $normalizedLabelName;
        }

        return $normalizedLabelNames;
    }

    private function requireTask(User $user, int $taskId): LocalTask
    {
        $task = $this->localTaskRepository->findOneByIdAndOwner($taskId, $user);
        if ($task === null) {
            throw new \InvalidArgumentException('Local task not found.');
        }

        return $task;
    }
}
