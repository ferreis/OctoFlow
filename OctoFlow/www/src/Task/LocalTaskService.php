<?php

namespace App\Task;

use App\Entity\LocalTask;
use App\Entity\User;
use App\Github\Exception\GithubConfigurationException;
use App\Github\Exception\GithubGraphQLException;
use App\Github\GithubIssuePublisherInterface;
use App\Github\GithubIssueTemplateCatalog;
use App\Repository\LocalTaskRepository;
use Doctrine\ORM\EntityManagerInterface;

final class LocalTaskService implements LocalTaskSyncInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LocalTaskRepository $localTaskRepository,
        private readonly GithubIssuePublisherInterface $issuePublisher,
        private readonly GithubIssueTemplateCatalog $templateCatalog,
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

        if ($title === '') {
            throw new \InvalidArgumentException('The local task title is required.');
        }

        if ($body === '') {
            throw new \InvalidArgumentException('The local task body is required.');
        }

        if (($repositoryOwner === '') xor ($repositoryName === '')) {
            throw new \InvalidArgumentException('Inform the synchronization repository owner and name together.');
        }

        if ($templateKey !== '' && $this->templateCatalog->find($templateKey) === null) {
            throw new \InvalidArgumentException('Unknown task template.');
        }

        $task = (new LocalTask())
            ->setOwner($user)
            ->setTemplateKey($templateKey !== '' ? $templateKey : null)
            ->setTitle($title)
            ->setBody($body)
            ->setRepositoryOwner($repositoryOwner !== '' ? $repositoryOwner : null)
            ->setRepositoryName($repositoryName !== '' ? $repositoryName : null)
            ->markPending();

        $this->entityManager->persist($task);
        $this->entityManager->flush();

        return $this->normalizeTask($task);
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
            'githubIssueId' => $task->getGithubIssueId(),
            'githubIssueNumber' => $task->getGithubIssueNumber(),
            'githubIssueUrl' => $task->getGithubIssueUrl(),
            'createdAt' => $task->getCreatedAt()->format(DATE_ATOM),
            'updatedAt' => $task->getUpdatedAt()->format(DATE_ATOM),
            'syncedAt' => $task->getSyncedAt()?->format(DATE_ATOM),
        ];
    }
}
