<?php

namespace App\Entity;

use App\Repository\GithubRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: GithubRepository::class)]
#[ORM\Table(name: 'github_repository')]
#[ORM\UniqueConstraint(name: 'uniq_github_repository_owner_key', columns: ['owner_id', 'owner_login', 'name'])]
class Github
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'githubRepositories')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $owner = null;

    #[ORM\Column(length: 191)]
    private string $ownerLogin = '';

    #[ORM\Column(length: 191)]
    private string $name = '';

    #[ORM\Column(length: 500)]
    private string $url = '';

    #[ORM\Column(options: ['default' => false])]
    private bool $isIgnored = false;

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

        if ($owner !== null && !$owner->getGithubRepositories()->contains($this)) {
            $owner->addGithubRepository($this);
        }

        return $this;
    }

    public function getOwnerLogin(): string
    {
        return $this->ownerLogin;
    }

    public function setOwnerLogin(string $ownerLogin): self
    {
        $this->ownerLogin = trim($ownerLogin);

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = trim($name);

        return $this;
    }

    public function getNameWithOwner(): string
    {
        if ($this->ownerLogin !== '' && $this->name !== '') {
            return sprintf('%s/%s', $this->ownerLogin, $this->name);
        }

        return '';
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

    public function isIgnored(): bool
    {
        return $this->isIgnored;
    }

    public function setIsIgnored(bool $isIgnored): self
    {
        $this->isIgnored = $isIgnored;

        return $this;
    }
}
