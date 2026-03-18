<?php

namespace App\Entity;

use App\Repository\GithubAccountRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: GithubAccountRepository::class)]
#[ORM\Table(name: 'github_account')]
#[ORM\UniqueConstraint(name: 'uniq_github_account_owner_login', columns: ['owner_id', 'account_login'])]
class GithubAccount
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'githubAccounts')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $owner = null;

    #[ORM\Column(name: 'account_login', length: 191)]
    private string $accountLogin = '';

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $tokenEncrypted = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    /**
     * @var Collection<int, Github>
     */
    #[ORM\OneToMany(targetEntity: Github::class, mappedBy: 'account', orphanRemoval: true, cascade: ['persist', 'remove'])]
    private Collection $repositories;

    public function __construct()
    {
        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
        $this->repositories = new ArrayCollection();
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

        if ($owner !== null && !$owner->getGithubAccounts()->contains($this)) {
            $owner->addGithubAccount($this);
        }

        return $this;
    }

    public function getAccountLogin(): string
    {
        return $this->accountLogin;
    }

    public function setAccountLogin(string $accountLogin): self
    {
        $this->accountLogin = trim($accountLogin);
        $this->touch();

        return $this;
    }

    public function getTokenEncrypted(): ?string
    {
        return $this->tokenEncrypted;
    }

    public function setTokenEncrypted(?string $tokenEncrypted): self
    {
        $normalized = $tokenEncrypted === null ? null : trim($tokenEncrypted);
        $this->tokenEncrypted = $normalized === '' ? null : $normalized;
        $this->touch();

        return $this;
    }

    public function hasTokenConfigured(): bool
    {
        return $this->tokenEncrypted !== null && $this->tokenEncrypted !== '';
    }

    /**
     * @return Collection<int, Github>
     */
    public function getRepositories(): Collection
    {
        return $this->repositories;
    }

    public function addRepository(Github $repository): self
    {
        if (!$this->repositories->contains($repository)) {
            $this->repositories->add($repository);
        }

        if ($repository->getAccount() !== $this) {
            $repository->setAccount($this);
        }

        return $this;
    }

    public function removeRepository(Github $repository): self
    {
        if ($this->repositories->removeElement($repository) && $repository->getAccount() === $this) {
            $repository->setAccount(null);
        }

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

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
