<?php

namespace App\Entity;

use App\Repository\GithubCachedIssueRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: GithubCachedIssueRepository::class)]
#[ORM\Table(name: 'github_cached_issue')]
#[ORM\UniqueConstraint(name: 'uniq_github_cached_issue_owner_issue', columns: ['owner_id', 'github_issue_id'])]
#[ORM\Index(name: 'idx_github_cached_issue_owner_updated', columns: ['owner_id', 'active', 'github_updated_at'])]
#[ORM\Index(name: 'idx_github_cached_issue_owner_repo', columns: ['owner_id', 'repository_key'])]
class GithubCachedIssue
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $owner = null;

    #[ORM\Column(length: 191)]
    private string $githubIssueId = '';

    #[ORM\Column]
    private int $issueNumber = 0;

    #[ORM\Column(length: 191)]
    private string $repositoryOwner = '';

    #[ORM\Column(length: 191)]
    private string $repositoryName = '';

    #[ORM\Column(length: 191)]
    private string $repositoryKey = '';

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $repositoryUrl = null;

    #[ORM\Column(length: 500)]
    private string $title = '';

    #[ORM\Column(type: 'text')]
    private string $body = '';

    #[ORM\Column(length: 16)]
    private string $state = 'OPEN';

    #[ORM\Column(length: 500)]
    private string $url = '';

    #[ORM\Column(length: 191, nullable: true)]
    private ?string $authorLogin = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $viewerCanUpdate = false;

    #[ORM\Column(options: ['default' => false])]
    private bool $viewerCanClose = false;

    #[ORM\Column(options: ['default' => false])]
    private bool $viewerCanReopen = false;

    #[ORM\Column(options: ['default' => false])]
    private bool $assignedToViewer = false;

    #[ORM\Column(options: ['default' => true])]
    private bool $active = true;

    /**
     * @var array<int, array<string, mixed>>
     */
    #[ORM\Column(type: 'json')]
    private array $assignees = [];

    /**
     * @var array<int, array<string, mixed>>
     */
    #[ORM\Column(type: 'json')]
    private array $labels = [];

    #[ORM\Column]
    private \DateTimeImmutable $githubCreatedAt;

    #[ORM\Column]
    private \DateTimeImmutable $githubUpdatedAt;

    #[ORM\Column]
    private \DateTimeImmutable $cachedAt;

    public function __construct()
    {
        $now = new \DateTimeImmutable();
        $this->githubCreatedAt = $now;
        $this->githubUpdatedAt = $now;
        $this->cachedAt = $now;
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

    public function getGithubIssueId(): string
    {
        return $this->githubIssueId;
    }

    public function setGithubIssueId(string $githubIssueId): self
    {
        $this->githubIssueId = trim($githubIssueId);

        return $this;
    }

    public function getIssueNumber(): int
    {
        return $this->issueNumber;
    }

    public function setIssueNumber(int $issueNumber): self
    {
        $this->issueNumber = $issueNumber;

        return $this;
    }

    public function getRepositoryOwner(): string
    {
        return $this->repositoryOwner;
    }

    public function setRepositoryOwner(string $repositoryOwner): self
    {
        $this->repositoryOwner = trim($repositoryOwner);

        return $this;
    }

    public function getRepositoryName(): string
    {
        return $this->repositoryName;
    }

    public function setRepositoryName(string $repositoryName): self
    {
        $this->repositoryName = trim($repositoryName);

        return $this;
    }

    public function getRepositoryKey(): string
    {
        return $this->repositoryKey;
    }

    public function setRepositoryKey(string $repositoryKey): self
    {
        $this->repositoryKey = trim($repositoryKey);

        return $this;
    }

    public function getRepositoryUrl(): ?string
    {
        return $this->repositoryUrl;
    }

    public function setRepositoryUrl(?string $repositoryUrl): self
    {
        $normalized = $repositoryUrl === null ? null : trim($repositoryUrl);
        $this->repositoryUrl = $normalized === '' ? null : $normalized;

        return $this;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): self
    {
        $this->title = trim($title);

        return $this;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function setBody(string $body): self
    {
        $this->body = $body;

        return $this;
    }

    public function getState(): string
    {
        return $this->state;
    }

    public function setState(string $state): self
    {
        $normalized = strtoupper(trim($state));
        $this->state = $normalized === 'CLOSED' ? 'CLOSED' : 'OPEN';

        return $this;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function setUrl(string $url): self
    {
        $this->url = trim($url);

        return $this;
    }

    public function getAuthorLogin(): ?string
    {
        return $this->authorLogin;
    }

    public function setAuthorLogin(?string $authorLogin): self
    {
        $normalized = $authorLogin === null ? null : trim($authorLogin);
        $this->authorLogin = $normalized === '' ? null : $normalized;

        return $this;
    }

    public function isViewerCanUpdate(): bool
    {
        return $this->viewerCanUpdate;
    }

    public function setViewerCanUpdate(bool $viewerCanUpdate): self
    {
        $this->viewerCanUpdate = $viewerCanUpdate;

        return $this;
    }

    public function isViewerCanClose(): bool
    {
        return $this->viewerCanClose;
    }

    public function setViewerCanClose(bool $viewerCanClose): self
    {
        $this->viewerCanClose = $viewerCanClose;

        return $this;
    }

    public function isViewerCanReopen(): bool
    {
        return $this->viewerCanReopen;
    }

    public function setViewerCanReopen(bool $viewerCanReopen): self
    {
        $this->viewerCanReopen = $viewerCanReopen;

        return $this;
    }

    public function isAssignedToViewer(): bool
    {
        return $this->assignedToViewer;
    }

    public function setAssignedToViewer(bool $assignedToViewer): self
    {
        $this->assignedToViewer = $assignedToViewer;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): self
    {
        $this->active = $active;

        return $this;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getAssignees(): array
    {
        return $this->assignees;
    }

    /**
     * @param array<int, array<string, mixed>> $assignees
     */
    public function setAssignees(array $assignees): self
    {
        $this->assignees = $assignees;

        return $this;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getLabels(): array
    {
        return $this->labels;
    }

    /**
     * @param array<int, array<string, mixed>> $labels
     */
    public function setLabels(array $labels): self
    {
        $this->labels = $labels;

        return $this;
    }

    public function getGithubCreatedAt(): \DateTimeImmutable
    {
        return $this->githubCreatedAt;
    }

    public function setGithubCreatedAt(\DateTimeImmutable $githubCreatedAt): self
    {
        $this->githubCreatedAt = $githubCreatedAt;

        return $this;
    }

    public function getGithubUpdatedAt(): \DateTimeImmutable
    {
        return $this->githubUpdatedAt;
    }

    public function setGithubUpdatedAt(\DateTimeImmutable $githubUpdatedAt): self
    {
        $this->githubUpdatedAt = $githubUpdatedAt;

        return $this;
    }

    public function getCachedAt(): \DateTimeImmutable
    {
        return $this->cachedAt;
    }

    public function setCachedAt(\DateTimeImmutable $cachedAt): self
    {
        $this->cachedAt = $cachedAt;

        return $this;
    }
}
