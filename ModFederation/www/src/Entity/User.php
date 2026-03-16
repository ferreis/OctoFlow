<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Entity\RefreshToken;
use App\Entity\UserEmail;
use App\Repository\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'app_user')]
#[UniqueEntity(fields: ['email'], message: 'There is already an account with this email.')]
#[ApiResource(
    operations: [
        new Get(security: "is_granted('ROLE_ADMIN')"),
        new GetCollection(security: "is_granted('ROLE_ADMIN')"),
    ],
    normalizationContext: ['groups' => ['user:read']]
)]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['user:read'])]
    private ?int $id = null;

    #[ORM\Column(length: 180, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Email]
    #[Groups(['user:read'])]
    private string $email = '';

    #[ORM\Column(length: 191, unique: true, nullable: true)]
    private ?string $googleSubject = null;

    #[ORM\Column(length: 191, nullable: true)]
    private ?string $githubRepositoryOwner = null;

    #[ORM\Column(length: 191, nullable: true)]
    private ?string $githubRepositoryName = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $githubTokenEncrypted = null;

    /**
     * @var list<string>
     */
    #[ORM\Column]
    #[Groups(['user:read'])]
    private array $roles = [];

    #[ORM\Column]
    private string $password = '';

    #[ORM\Column(options: ['default' => true])]
    #[Groups(['user:read'])]
    private bool $isActive = true;

    #[ORM\Column]
    #[Groups(['user:read'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    #[Groups(['user:read'])]
    private \DateTimeImmutable $updatedAt;

    /**
     * @var Collection<int, RefreshToken>
     */
    #[ORM\OneToMany(targetEntity: RefreshToken::class, mappedBy: 'user', orphanRemoval: true)]
    private Collection $refreshTokens;

    /**
     * @var Collection<int, UserEmail>
     */
    #[ORM\OneToMany(targetEntity: UserEmail::class, mappedBy: 'user', orphanRemoval: true, cascade: ['persist'])]
    private Collection $emailAddresses;

    public function __construct()
    {
        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
        $this->refreshTokens = new ArrayCollection();
        $this->emailAddresses = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): self
    {
        $this->email = mb_strtolower(trim($email));
        $this->touch();

        return $this;
    }

    public function getGoogleSubject(): ?string
    {
        return $this->googleSubject;
    }

    public function setGoogleSubject(?string $googleSubject): self
    {
        $normalized = $googleSubject === null ? null : trim($googleSubject);
        $this->googleSubject = $normalized === '' ? null : $normalized;
        $this->touch();

        return $this;
    }

    public function getGithubRepositoryOwner(): ?string
    {
        return $this->githubRepositoryOwner;
    }

    public function setGithubRepositoryOwner(?string $githubRepositoryOwner): self
    {
        $normalized = $githubRepositoryOwner === null ? null : trim($githubRepositoryOwner);
        $this->githubRepositoryOwner = $normalized === '' ? null : $normalized;
        $this->touch();

        return $this;
    }

    public function getGithubRepositoryName(): ?string
    {
        return $this->githubRepositoryName;
    }

    public function setGithubRepositoryName(?string $githubRepositoryName): self
    {
        $normalized = $githubRepositoryName === null ? null : trim($githubRepositoryName);
        $this->githubRepositoryName = $normalized === '' ? null : $normalized;
        $this->touch();

        return $this;
    }

    public function getGithubTokenEncrypted(): ?string
    {
        return $this->githubTokenEncrypted;
    }

    public function setGithubTokenEncrypted(?string $githubTokenEncrypted): self
    {
        $normalized = $githubTokenEncrypted === null ? null : trim($githubTokenEncrypted);
        $this->githubTokenEncrypted = $normalized === '' ? null : $normalized;
        $this->touch();

        return $this;
    }

    public function hasGithubTokenConfigured(): bool
    {
        return $this->githubTokenEncrypted !== null && $this->githubTokenEncrypted !== '';
    }

    public function hasGithubWorkspaceConfiguration(): bool
    {
        return $this->hasGithubTokenConfigured()
            && $this->githubRepositoryOwner !== null
            && $this->githubRepositoryOwner !== ''
            && $this->githubRepositoryName !== null
            && $this->githubRepositoryName !== '';
    }

    /**
     * A visual identifier that represents this user.
     */
    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    /**
     * @return list<string>
     */
    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = 'ROLE_USER';

        return array_values(array_unique($roles));
    }

    /**
     * @param list<string> $roles
     */
    public function setRoles(array $roles): self
    {
        $normalized = array_map(static fn (string $role): string => strtoupper(trim($role)), $roles);
        $this->roles = array_values(array_unique(array_filter($normalized)));
        $this->touch();

        return $this;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $password): self
    {
        $this->password = $password;
        $this->touch();

        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function getIsActive(): bool
    {
        return $this->isActive();
    }

    public function setIsActive(bool $isActive): self
    {
        $this->isActive = $isActive;
        $this->touch();

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): self
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeImmutable $updatedAt): self
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }

    /**
     * @return Collection<int, RefreshToken>
     */
    public function getRefreshTokens(): Collection
    {
        return $this->refreshTokens;
    }

    public function addRefreshToken(RefreshToken $refreshToken): self
    {
        if (!$this->refreshTokens->contains($refreshToken)) {
            $this->refreshTokens->add($refreshToken);
            $refreshToken->setUser($this);
        }

        return $this;
    }

    public function removeRefreshToken(RefreshToken $refreshToken): self
    {
        if ($this->refreshTokens->removeElement($refreshToken) && $refreshToken->getUser() === $this) {
            $refreshToken->setUser(null);
        }

        return $this;
    }

    /**
     * @return Collection<int, UserEmail>
     */
    public function getEmailAddresses(): Collection
    {
        return $this->emailAddresses;
    }

    public function addEmailAddress(UserEmail $emailAddress): self
    {
        if (!$this->emailAddresses->contains($emailAddress)) {
            $this->emailAddresses->add($emailAddress);
            $emailAddress->setUser($this);
        }

        return $this;
    }

    public function removeEmailAddress(UserEmail $emailAddress): self
    {
        if ($this->emailAddresses->removeElement($emailAddress) && $emailAddress->getUser() === $this) {
            $emailAddress->setUser(null);
        }

        return $this;
    }

    public function eraseCredentials(): void
    {
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
