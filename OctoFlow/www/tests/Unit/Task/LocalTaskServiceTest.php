<?php

namespace App\Tests\Unit\Task;

use App\Entity\LocalTask;
use App\Entity\User;
use App\Github\GithubIssuePublisherInterface;
use App\Github\GithubIssueTemplateCatalog;
use App\Github\GithubRegistryService;
use App\Github\TemplateAccessService;
use App\Github\UserCapabilityResolver;
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
    private GithubRegistryService&MockObject $registryService;
    private LocalTaskService $service;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->localTaskRepository = $this->createMock(LocalTaskRepository::class);
        $this->issuePublisher = $this->createMock(GithubIssuePublisherInterface::class);
        $this->registryService = $this->createMock(GithubRegistryService::class);
        $this->service = new LocalTaskService(
            $this->entityManager,
            $this->localTaskRepository,
            $this->issuePublisher,
            new GithubIssueTemplateCatalog(),
            new TemplateAccessService(new UserCapabilityResolver()),
            $this->registryService,
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
            'labelNames' => ['frontend', 'api', 'frontend'],
        ]);

        $this->assertSame('[feat] Painel local', $item['title']);
        $this->assertSame(LocalTask::SYNC_STATE_PENDING, $item['syncState']);
        $this->assertNull($item['repositoryKey']);
        $this->assertSame(['frontend', 'api'], $item['labelNames']);
        $this->assertSame([], $item['historyEntries']);
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
            ->setLabelNames(['frontend', 'api'])
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
                'newLabelNames' => ['frontend', 'api'],
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

    public function testBuildBoardSkipsTasksFromRepositoriesOutsideCurrentWorkspace(): void
    {
        $user = (new User())
            ->setEmail('owner@example.com')
            ->setPassword('not-used');

        $activeTask = (new LocalTask())
            ->setOwner($user)
            ->setTitle('[feat] Repo atual')
            ->setBody('Conteudo do repo atual')
            ->setRepositoryOwner('acme')
            ->setRepositoryName('delivery-desk')
            ->markPending();

        $staleTask = (new LocalTask())
            ->setOwner($user)
            ->setTitle('[feat] Repo antigo')
            ->setBody('Conteudo do repo antigo')
            ->setRepositoryOwner('legacy')
            ->setRepositoryName('old-project')
            ->markPending();

        $genericTask = (new LocalTask())
            ->setOwner($user)
            ->setTitle('[feat] Sem repo')
            ->setBody('Conteudo sem repo')
            ->markPending();

        $this->localTaskRepository
            ->expects($this->once())
            ->method('findUnsyncedByOwner')
            ->with($user)
            ->willReturn([$activeTask, $staleTask, $genericTask]);

        $this->registryService
            ->expects($this->once())
            ->method('buildCatalog')
            ->with($user, false)
            ->willReturn([
                ['nameWithOwner' => 'acme/delivery-desk'],
            ]);

        $board = $this->service->buildBoard($user);

        $this->assertCount(2, $board['items']);
        $this->assertSame(
            ['[feat] Repo atual', '[feat] Sem repo'],
            array_map(static fn (array $item): string => $item['title'], $board['items']),
        );
        $this->assertSame(['total' => 2, 'pending' => 2, 'failed' => 0], $board['stats']);
    }

    public function testUpdateTaskChangesTitleBodyAndRepository(): void
    {
        $user = (new User())
            ->setEmail('owner@example.com')
            ->setPassword('not-used');

        $task = (new LocalTask())
            ->setOwner($user)
            ->setTemplateKey('feature-request')
            ->setTitle('[feat] Painel local')
            ->setBody('Conteudo antigo')
            ->setLabelNames(['frontend'])
            ->markPending();

        $this->localTaskRepository
            ->expects($this->once())
            ->method('findOneByIdAndOwner')
            ->with(15, $user)
            ->willReturn($task);

        $this->registryService
            ->expects($this->once())
            ->method('buildCatalog')
            ->with($user, false)
            ->willReturn([
                ['nameWithOwner' => 'acme/delivery-desk'],
            ]);

        $this->entityManager
            ->expects($this->once())
            ->method('flush');

        $updated = $this->service->updateTask($user, 15, [
            'title' => '[feat] Painel local revisado',
            'body' => 'Conteudo novo',
            'templateKey' => 'feature-request',
            'labelNames' => ['backend', 'ci/cd', 'backend'],
            'repositoryOwner' => 'acme',
            'repositoryName' => 'delivery-desk',
        ]);

        $this->assertSame('[feat] Painel local revisado', $updated['title']);
        $this->assertSame('Conteudo novo', $updated['body']);
        $this->assertSame('acme/delivery-desk', $updated['repositoryKey']);
        $this->assertSame(LocalTask::SYNC_STATE_PENDING, $updated['syncState']);
        $this->assertSame(['backend', 'ci/cd'], $updated['labelNames']);
        $this->assertCount(2, $updated['historyEntries']);
        $this->assertSame('label-added', $updated['historyEntries'][0]['kind']);
        $this->assertSame('label-removed', $updated['historyEntries'][1]['kind']);
    }

    public function testCreateTaskRejectsRepositoryOutsideCurrentWorkspace(): void
    {
        $user = (new User())
            ->setEmail('owner@example.com')
            ->setPassword('not-used');

        $this->registryService
            ->expects($this->once())
            ->method('buildCatalog')
            ->with($user, false)
            ->willReturn([
                ['nameWithOwner' => 'acme/delivery-desk'],
            ]);

        $this->entityManager
            ->expects($this->never())
            ->method('persist');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The selected GitHub repository is not available in your current workspace.');

        $this->service->createTask($user, [
            'templateKey' => 'feature-request',
            'title' => '[feat] Repo invalido',
            'body' => 'Nao deve persistir',
            'repositoryOwner' => 'legacy',
            'repositoryName' => 'old-project',
        ]);
    }

    public function testSyncTaskToGithubRequiresRepositorySelection(): void
    {
        $user = (new User())
            ->setEmail('owner@example.com')
            ->setPassword('not-used');

        $task = (new LocalTask())
            ->setOwner($user)
            ->setTemplateKey('feature-request')
            ->setTitle('[feat] Painel local')
            ->setBody('Conteudo da tarefa local')
            ->setLabelNames(['frontend', 'api'])
            ->markPending();

        $this->localTaskRepository
            ->expects($this->once())
            ->method('findOneByIdAndOwner')
            ->with(8, $user)
            ->willReturn($task);

        $this->issuePublisher
            ->expects($this->never())
            ->method('createDraftIssue');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Select the GitHub repository before synchronizing this local task.');

        $this->service->syncTaskToGithub($user, 8, []);
    }

    public function testSyncTaskToGithubPublishesUsingExplicitRepository(): void
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
            ->setLabelNames(['frontend', 'api'])
            ->markPending();

        $this->localTaskRepository
            ->expects($this->once())
            ->method('findOneByIdAndOwner')
            ->with(9, $user)
            ->willReturn($task);

        $this->registryService
            ->expects($this->once())
            ->method('buildCatalog')
            ->with($user, false)
            ->willReturn([
                ['nameWithOwner' => 'acme/delivery-desk'],
            ]);

        $this->issuePublisher
            ->expects($this->once())
            ->method('createDraftIssue')
            ->with($user, [
                'title' => '[feat] Painel local',
                'body' => 'Conteudo da tarefa local',
                'newLabelNames' => ['frontend', 'api'],
                'repositoryOwner' => 'acme',
                'repositoryName' => 'delivery-desk',
            ])
            ->willReturn([
                'issue' => [
                    'id' => 'issue-node-id',
                    'number' => 77,
                    'url' => 'https://github.com/acme/delivery-desk/issues/77',
                ],
            ]);

        $this->entityManager
            ->expects($this->once())
            ->method('flush');

        $result = $this->service->syncTaskToGithub($user, 9, [
            'repositoryOwner' => 'acme',
            'repositoryName' => 'delivery-desk',
        ]);

        $this->assertSame(LocalTask::SYNC_STATE_SYNCED, $result['item']['syncState']);
        $this->assertSame(77, $result['github']['issue']['number']);
        $this->assertSame('acme/delivery-desk', $result['item']['repositoryKey']);
    }
}
