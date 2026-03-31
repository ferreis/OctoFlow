<?php

namespace App\Entity;

use App\Repository\LocalTaskRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: LocalTaskRepository::class)]
#[ORM\Table(name: 'local_task')]
#[ORM\Index(name: 'idx_local_task_owner_sync_state', columns: ['owner_id', 'sync_state', 'updated_at'])]
#[ORM\Index(name: 'idx_local_task_owner_created', columns: ['owner_id', 'created_at'])]
class LocalTask
{
    public const STATE_OPEN = 'OPEN';
    public const STATE_CLOSED = 'CLOSED';
    public const SYNC_STATE_PENDING = 'PENDING';
    public const SYNC_STATE_FAILED = 'FAILED';
    public const SYNC_STATE_SYNCED = 'SYNCED';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $owner = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $templateKey = null;

    #[ORM\Column(length: 500)]
    private string $title = '';

    #[ORM\Column(type: 'text')]
    private string $body = '';

    #[ORM\Column(length: 16)]
    private string $state = self::STATE_OPEN;

    #[ORM\Column(name: 'sync_state', length: 16)]
    private string $syncState = self::SYNC_STATE_PENDING;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $syncError = null;

    #[ORM\Column(length: 191, nullable: true)]
    private ?string $repositoryOwner = null;

    #[ORM\Column(length: 191, nullable: true)]
    private ?string $repositoryName = null;

    /**
     * @var list<string>
     */
    #[ORM\Column(type: 'json', options: ['default' => '[]'])]
    private array $labelNames = [];

    /**
     * @var list<array{id: string, kind: string, title: string, description: string, createdAt: string, url?: string}>
     */
    #[ORM\Column(type: 'json', options: ['default' => '[]'])]
    private array $historyEntries = [];

    #[ORM\Column(length: 191, nullable: true)]
    private ?string $githubIssueId = null;

    #[ORM\Column(nullable: true)]
    private ?int $githubIssueNumber = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $githubIssueUrl = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $syncedAt = null;

    public function __construct()
    {
        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOwner(): ?User
    {
        return $this->owner;
    }

    public function setOwner(?User $owner): self
    {
        $this->owner = $owner;

        return $this;
    }

    public function getTemplateKey(): ?string
    {
        return $this->templateKey;
    }

    public function setTemplateKey(?string $templateKey): self
    {
        $normalized = $templateKey === null ? null : trim($templateKey);
        $this->templateKey = $normalized === '' ? null : $normalized;
        $this->touch();

        return $this;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): self
    {
        $this->title = trim($title);
        $this->touch();

        return $this;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function setBody(string $body): self
    {
        $this->body = trim($body);
        $this->touch();

        return $this;
    }

    public function getState(): string
    {
        return $this->state;
    }

    public function setState(string $state): self
    {
        $normalized = strtoupper(trim($state));
        $this->state = $normalized === self::STATE_CLOSED ? self::STATE_CLOSED : self::STATE_OPEN;
        $this->touch();

        return $this;
    }

    public function getSyncState(): string
    {
        return $this->syncState;
    }

    public function setSyncState(string $syncState): self
    {
        $normalized = strtoupper(trim($syncState));
        $this->syncState = in_array($normalized, [self::SYNC_STATE_PENDING, self::SYNC_STATE_FAILED, self::SYNC_STATE_SYNCED], true)
            ? $normalized
            : self::SYNC_STATE_PENDING;
        $this->touch();

        return $this;
    }

    public function getSyncError(): ?string
    {
        return $this->syncError;
    }

    public function setSyncError(?string $syncError): self
    {
        $normalized = $syncError === null ? null : trim($syncError);
        $this->syncError = $normalized === '' ? null : $normalized;
        $this->touch();

        return $this;
    }

    public function getRepositoryOwner(): ?string
    {
        return $this->repositoryOwner;
    }

    public function setRepositoryOwner(?string $repositoryOwner): self
    {
        $normalized = $repositoryOwner === null ? null : trim($repositoryOwner);
        $this->repositoryOwner = $normalized === '' ? null : $normalized;
        $this->touch();

        return $this;
    }

    public function getRepositoryName(): ?string
    {
        return $this->repositoryName;
    }

    public function setRepositoryName(?string $repositoryName): self
    {
        $normalized = $repositoryName === null ? null : trim($repositoryName);
        $this->repositoryName = $normalized === '' ? null : $normalized;
        $this->touch();

        return $this;
    }

    public function getRepositoryKey(): ?string
    {
        if (($this->repositoryOwner ?? '') === '' || ($this->repositoryName ?? '') === '') {
            return null;
        }

        return sprintf('%s/%s', $this->repositoryOwner, $this->repositoryName);
    }

    /**
     * @return list<string>
     */
    public function getLabelNames(): array
    {
        return $this->labelNames;
    }

    /**
     * @param list<string> $labelNames
     */
    public function setLabelNames(array $labelNames): self
    {
        $normalizedLabelNames = [];
        $seenLabelKeys = [];

        foreach ($labelNames as $labelName) {
            $normalizedLabelName = preg_replace('/\s+/', ' ', trim((string) $labelName));
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

        $this->labelNames = $normalizedLabelNames;
        $this->touch();

        return $this;
    }

    /**
     * @return list<array{id: string, kind: string, title: string, description: string, createdAt: string, url?: string}>
     */
    public function getHistoryEntries(): array
    {
        return $this->historyEntries;
    }

    /**
     * @param list<array{id: string, kind: string, title: string, description: string, createdAt: string, url?: string}> $historyEntries
     */
    public function setHistoryEntries(array $historyEntries): self
    {
        $normalizedHistoryEntries = [];

        foreach ($historyEntries as $historyEntry) {
            if (!is_array($historyEntry)) {
                continue;
            }

            $normalizedKind = strtolower(trim((string) ($historyEntry['kind'] ?? 'updated')));
            if ($normalizedKind === '') {
                $normalizedKind = 'updated';
            }

            $normalizedTitle = trim((string) ($historyEntry['title'] ?? 'Atualização registrada'));
            if ($normalizedTitle === '') {
                $normalizedTitle = 'Atualização registrada';
            }

            $normalizedDescription = trim((string) ($historyEntry['description'] ?? 'Sem detalhes adicionais.'));
            if ($normalizedDescription === '') {
                $normalizedDescription = 'Sem detalhes adicionais.';
            }

            $normalizedCreatedAt = trim((string) ($historyEntry['createdAt'] ?? ''));
            if ($normalizedCreatedAt === '') {
                $normalizedCreatedAt = (new \DateTimeImmutable())->format(DATE_ATOM);
            }

            $normalizedId = trim((string) ($historyEntry['id'] ?? ''));
            if ($normalizedId === '') {
                $normalizedId = sprintf(
                    'local-task-history-%s-%s',
                    $normalizedKind,
                    str_replace('.', '', uniqid('', true))
                );
            }

            $normalizedEntry = [
                'id' => $normalizedId,
                'kind' => $normalizedKind,
                'title' => $normalizedTitle,
                'description' => $normalizedDescription,
                'createdAt' => $normalizedCreatedAt,
            ];

            $normalizedUrl = trim((string) ($historyEntry['url'] ?? ''));
            if ($normalizedUrl !== '') {
                $normalizedEntry['url'] = $normalizedUrl;
            }

            $normalizedHistoryEntries[] = $normalizedEntry;
        }

        $this->historyEntries = $normalizedHistoryEntries;
        $this->touch();

        return $this;
    }

    public function appendHistoryEntry(string $kind, string $title, string $description, ?string $url = null): self
    {
        $normalizedKind = strtolower(trim($kind));
        if ($normalizedKind === '') {
            $normalizedKind = 'updated';
        }

        $normalizedTitle = trim($title);
        if ($normalizedTitle === '') {
            $normalizedTitle = 'Atualização registrada';
        }

        $normalizedDescription = trim($description);
        if ($normalizedDescription === '') {
            $normalizedDescription = 'Sem detalhes adicionais.';
        }

        $normalizedEntry = [
            'id' => sprintf(
                'local-task-history-%s-%s',
                $normalizedKind,
                str_replace('.', '', uniqid('', true))
            ),
            'kind' => $normalizedKind,
            'title' => $normalizedTitle,
            'description' => $normalizedDescription,
            'createdAt' => (new \DateTimeImmutable())->format(DATE_ATOM),
        ];

        $normalizedUrl = $url === null ? '' : trim($url);
        if ($normalizedUrl !== '') {
            $normalizedEntry['url'] = $normalizedUrl;
        }

        $this->historyEntries[] = $normalizedEntry;
        $this->touch();

        return $this;
    }

    public function getGithubIssueId(): ?string
    {
        return $this->githubIssueId;
    }

    public function setGithubIssueId(?string $githubIssueId): self
    {
        $normalized = $githubIssueId === null ? null : trim($githubIssueId);
        $this->githubIssueId = $normalized === '' ? null : $normalized;
        $this->touch();

        return $this;
    }

    public function getGithubIssueNumber(): ?int
    {
        return $this->githubIssueNumber;
    }

    public function setGithubIssueNumber(?int $githubIssueNumber): self
    {
        $this->githubIssueNumber = $githubIssueNumber;
        $this->touch();

        return $this;
    }

    public function getGithubIssueUrl(): ?string
    {
        return $this->githubIssueUrl;
    }

    public function setGithubIssueUrl(?string $githubIssueUrl): self
    {
        $normalized = $githubIssueUrl === null ? null : trim($githubIssueUrl);
        $this->githubIssueUrl = $normalized === '' ? null : $normalized;
        $this->touch();

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getSyncedAt(): ?\DateTimeImmutable
    {
        return $this->syncedAt;
    }

    public function setSyncedAt(?\DateTimeImmutable $syncedAt): self
    {
        $this->syncedAt = $syncedAt;
        $this->touch();

        return $this;
    }

    public function markPending(?string $error = null): self
    {
        return $this
            ->setSyncState(self::SYNC_STATE_PENDING)
            ->setSyncError($error);
    }

    public function markFailed(string $error): self
    {
        return $this
            ->setSyncState(self::SYNC_STATE_FAILED)
            ->setSyncError($error);
    }

    public function markSynced(array $issue): self
    {
        return $this
            ->setSyncState(self::SYNC_STATE_SYNCED)
            ->setSyncError(null)
            ->setGithubIssueId((string) ($issue['id'] ?? ''))
            ->setGithubIssueNumber(isset($issue['number']) ? (int) $issue['number'] : null)
            ->setGithubIssueUrl((string) ($issue['url'] ?? ''))
            ->setSyncedAt(new \DateTimeImmutable());
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
