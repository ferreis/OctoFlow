<?php

namespace App\Entity;

use App\Repository\GithubIssueSyncStateRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: GithubIssueSyncStateRepository::class)]
#[ORM\Table(name: 'github_issue_sync_state')]
#[ORM\UniqueConstraint(name: 'uniq_github_issue_sync_state_owner_scope_repo', columns: ['owner_id', 'scope', 'repository_key'])]
class GithubIssueSyncState
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $owner = null;

    #[ORM\Column(length: 32)]
    private string $scope = 'all';

    #[ORM\Column(length: 191)]
    private string $repositoryKey = '';

    #[ORM\Column(length: 191, nullable: true)]
    private ?string $viewerLogin = null;

    /**
     * @var array<int, array<string, mixed>>
     */
    #[ORM\Column(type: 'json')]
    private array $repositoryCatalog = [];

    #[ORM\Column]
    private \DateTimeImmutable $syncedAt;

    public function __construct()
    {
        $this->syncedAt = new \DateTimeImmutable();
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

    public function getScope(): string
    {
        return $this->scope;
    }

    public function setScope(string $scope): self
    {
        $this->scope = trim(strtolower($scope));

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

    public function getViewerLogin(): ?string
    {
        return $this->viewerLogin;
    }

    public function setViewerLogin(?string $viewerLogin): self
    {
        $normalized = $viewerLogin === null ? null : trim($viewerLogin);
        $this->viewerLogin = $normalized === '' ? null : $normalized;

        return $this;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getRepositoryCatalog(): array
    {
        return $this->repositoryCatalog;
    }

    /**
     * @param array<int, array<string, mixed>> $repositoryCatalog
     */
    public function setRepositoryCatalog(array $repositoryCatalog): self
    {
        $this->repositoryCatalog = $repositoryCatalog;

        return $this;
    }

    public function getSyncedAt(): \DateTimeImmutable
    {
        return $this->syncedAt;
    }

    public function setSyncedAt(\DateTimeImmutable $syncedAt): self
    {
        $this->syncedAt = $syncedAt;

        return $this;
    }
}
