<?php

namespace App\Tests\Unit\Task;

use App\Entity\LocalTask;
use App\Entity\User;
use App\Github\GithubIssuePublisherInterface;
use App\Github\GithubIssueTemplateCatalog;
use App\Repository\LocalTaskRepository;
use App\Task\LocalTaskService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class LocalTaskServiceTest extends TestCase
{
    private EntityManagerInterface&MockObject $entityManager;
    private LocalTaskRepository&MockObject $localTaskRepository;
    private GithubIssuePublisherInterface&MockObject $issuePublisher;
    private LocalTaskService $service;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->localTaskRepository = $this->createMock(LocalTaskRepository::class);
        $this->issuePublisher = $this->createMock(GithubIssuePublisherInterface::class);
        $this->service = new LocalTaskService(
            $this->entityManager,
            $this->localTaskRepository,
            $this->issuePublisher,
            new GithubIssueTemplateCatalog(),
        );
    }

    public function testCreateTaskPersistsPendingLocalTask(): void
    {
        $user = (new User())
            ->setEmail('owner@example.com')
            ->setPassword('not-used');

        $this->entityManager
            ->expects($this->once())
            ->method('persist')
            ->with($this->isInstanceOf(LocalTask::class));

        $this->entityManager
            ->expects($this->once())
            ->method('flush');

        $item = $this->service->createTask($user, [
            'templateKey' => 'feature-request',
            'title' => '[feat] Painel local',
            'body' => 'Conteudo da tarefa local',
        ]);

        $this->assertSame('[feat] Painel local', $item['title']);
        $this->assertSame(LocalTask::SYNC_STATE_PENDING, $item['syncState']);
        $this->assertNull($item['repositoryKey']);
    }

    public function testSyncPendingTasksMarksTaskAsSynced(): void
    {
        $user = (new User())
            ->setEmail('owner@example.com')
            ->setPassword('not-used')
            ->setGithubTokenEncrypted('token-ja-configurado');

        $task = (new LocalTask())
            ->setOwner($user)
            ->setTemplateKey('feature-request')
            ->setTitle('[feat] Painel local')
            ->setBody('Conteudo da tarefa local')
            ->markPending();

        $this->localTaskRepository
            ->expects($this->once())
            ->method('findSyncableByOwner')
            ->with($user)
            ->willReturn([$task]);

        $this->issuePublisher
            ->expects($this->once())
            ->method('createDraftIssue')
            ->with($user, [
                'title' => '[feat] Painel local',
                'body' => 'Conteudo da tarefa local',
                'repositoryOwner' => null,
                'repositoryName' => null,
            ])
            ->willReturn([
                'issue' => [
                    'id' => 'issue-node-id',
                    'number' => 42,
                    'url' => 'https://github.com/acme/delivery-desk/issues/42',
                ],
            ]);

        $this->entityManager
            ->expects($this->once())
            ->method('flush');

        $result = $this->service->syncPendingTasks($user);

        $this->assertSame(['synced' => 1, 'failed' => 0, 'skipped' => 0], $result);
        $this->assertSame(LocalTask::SYNC_STATE_SYNCED, $task->getSyncState());
        $this->assertSame('issue-node-id', $task->getGithubIssueId());
        $this->assertSame(42, $task->getGithubIssueNumber());
    }
}
