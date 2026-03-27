<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Entity\GithubAccount;
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

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $githubTokenEncrypted = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $avatarPath = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $avatarMimeType = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $avatarUpdatedAt = null;

    /**
     * @var list<string>
     */
    #[ORM\Column]
    #[Groups(['user:read'])]
    private array $roles = [];

    #[ORM\Column]
    private string $password = '';

    #[ORM\Column(options: ['default' => true])]
    private bool $passwordLoginEnabled = true;

    #[ORM\Column(length: 191, nullable: true)]
    private ?string $googlePasswordSetupCodeHash = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $googlePasswordSetupCodeExpiresAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $googlePasswordSetupVerifiedAt = null;

    #[ORM\Column(length: 191, nullable: true)]
    private ?string $passwordChangeCodeHash = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $passwordChangeCodeExpiresAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $passwordChangeCodeVerifiedAt = null;

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

    /**
     * @var Collection<int, Github>
     */
    #[ORM\OneToMany(targetEntity: Github::class, mappedBy: 'owner', orphanRemoval: true, cascade: ['persist', 'remove'])]
    private Collection $githubRepositories;

    /**
     * @var Collection<int, GithubAccount>
     */
    #[ORM\OneToMany(targetEntity: GithubAccount::class, mappedBy: 'owner', orphanRemoval: true, cascade: ['persist', 'remove'])]
    private Collection $githubAccounts;

    #[ORM\OneToOne(mappedBy: 'user', targetEntity: UISettings::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private ?UISettings $uiSettings = null;

    public function __construct()
    {
        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
        $this->refreshTokens = new ArrayCollection();
        $this->emailAddresses = new ArrayCollection();
        $this->githubRepositories = new ArrayCollection();
        $this->githubAccounts = new ArrayCollection();
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

    public function getAvatarPath(): ?string
    {
        return $this->avatarPath;
    }

    public function setAvatarPath(?string $avatarPath): self
    {
        $normalized = $avatarPath === null ? null : trim($avatarPath);
        $this->avatarPath = $normalized === '' ? null : $normalized;
        $this->touch();

        return $this;
    }

    public function getAvatarMimeType(): ?string
    {
        return $this->avatarMimeType;
    }

    public function setAvatarMimeType(?string $avatarMimeType): self
    {
        $normalized = $avatarMimeType === null ? null : trim($avatarMimeType);
        $this->avatarMimeType = $normalized === '' ? null : $normalized;
        $this->touch();

        return $this;
    }

    public function getAvatarUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->avatarUpdatedAt;
    }

    public function setAvatarUpdatedAt(?\DateTimeImmutable $avatarUpdatedAt): self
    {
        $this->avatarUpdatedAt = $avatarUpdatedAt;
        $this->touch();

        return $this;
    }

    public function hasGithubTokenConfigured(): bool
    {
        if ($this->githubTokenEncrypted !== null && $this->githubTokenEncrypted !== '') {
            return true;
        }

        foreach ($this->githubAccounts as $account) {
            if ($account->hasTokenConfigured()) {
                return true;
            }
        }

        return false;
    }

    public function hasGithubWorkspaceConfiguration(): bool
    {
        return $this->hasGithubTokenConfigured();
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

    public function isPasswordLoginEnabled(): bool
    {
        return $this->passwordLoginEnabled;
    }

    public function setPasswordLoginEnabled(bool $passwordLoginEnabled): self
    {
        $this->passwordLoginEnabled = $passwordLoginEnabled;
        $this->touch();

        return $this;
    }

    public function getGooglePasswordSetupCodeHash(): ?string
    {
        return $this->googlePasswordSetupCodeHash;
    }

    public function setGooglePasswordSetupCodeHash(?string $googlePasswordSetupCodeHash): self
    {
        $normalizedCodeHash = $googlePasswordSetupCodeHash === null ? null : trim($googlePasswordSetupCodeHash);
        $this->googlePasswordSetupCodeHash = $normalizedCodeHash === '' ? null : $normalizedCodeHash;
        $this->touch();

        return $this;
    }

    public function getGooglePasswordSetupCodeExpiresAt(): ?\DateTimeImmutable
    {
        return $this->googlePasswordSetupCodeExpiresAt;
    }

    public function setGooglePasswordSetupCodeExpiresAt(?\DateTimeImmutable $googlePasswordSetupCodeExpiresAt): self
    {
        $this->googlePasswordSetupCodeExpiresAt = $googlePasswordSetupCodeExpiresAt;
        $this->touch();

        return $this;
    }

    public function getGooglePasswordSetupVerifiedAt(): ?\DateTimeImmutable
    {
        return $this->googlePasswordSetupVerifiedAt;
    }

    public function setGooglePasswordSetupVerifiedAt(?\DateTimeImmutable $googlePasswordSetupVerifiedAt): self
    {
        $this->googlePasswordSetupVerifiedAt = $googlePasswordSetupVerifiedAt;
        $this->touch();

        return $this;
    }

    public function getPasswordChangeCodeHash(): ?string
    {
        return $this->passwordChangeCodeHash;
    }

    public function setPasswordChangeCodeHash(?string $passwordChangeCodeHash): self
    {
        $normalizedCodeHash = $passwordChangeCodeHash === null ? null : trim($passwordChangeCodeHash);
        $this->passwordChangeCodeHash = $normalizedCodeHash === '' ? null : $normalizedCodeHash;
        $this->touch();

        return $this;
    }

    public function getPasswordChangeCodeExpiresAt(): ?\DateTimeImmutable
    {
        return $this->passwordChangeCodeExpiresAt;
    }

    public function setPasswordChangeCodeExpiresAt(?\DateTimeImmutable $passwordChangeCodeExpiresAt): self
    {
        $this->passwordChangeCodeExpiresAt = $passwordChangeCodeExpiresAt;
        $this->touch();

        return $this;
    }

    public function getPasswordChangeCodeVerifiedAt(): ?\DateTimeImmutable
    {
        return $this->passwordChangeCodeVerifiedAt;
    }

    public function setPasswordChangeCodeVerifiedAt(?\DateTimeImmutable $passwordChangeCodeVerifiedAt): self
    {
        $this->passwordChangeCodeVerifiedAt = $passwordChangeCodeVerifiedAt;
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

    /**
     * @return Collection<int, Github>
     */
    public function getGithubRepositories(): Collection
    {
        return $this->githubRepositories;
    }

    public function addGithubRepository(Github $githubRepository): self
    {
        if (!$this->githubRepositories->contains($githubRepository)) {
            $this->githubRepositories->add($githubRepository);
        }

        if ($githubRepository->getOwner() !== $this) {
            $githubRepository->setOwner($this);
        }

        return $this;
    }

    /**
     * @return Collection<int, GithubAccount>
     */
    public function getGithubAccounts(): Collection
    {
        return $this->githubAccounts;
    }

    public function addGithubAccount(GithubAccount $githubAccount): self
    {
        if (!$this->githubAccounts->contains($githubAccount)) {
            $this->githubAccounts->add($githubAccount);
        }

        if ($githubAccount->getOwner() !== $this) {
            $githubAccount->setOwner($this);
        }

        return $this;
    }

    public function removeGithubAccount(GithubAccount $githubAccount): self
    {
        if ($this->githubAccounts->removeElement($githubAccount) && $githubAccount->getOwner() === $this) {
            $githubAccount->setOwner(null);
        }

        return $this;
    }

    public function removeGithubRepository(Github $githubRepository): self
    {
        if ($this->githubRepositories->removeElement($githubRepository) && $githubRepository->getOwner() === $this) {
            $githubRepository->setOwner(null);
        }

        return $this;
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

    public function getUiSettings(): ?UISettings
    {
        return $this->uiSettings;
    }

    public function setUiSettings(?UISettings $uiSettings): self
    {
        $this->uiSettings = $uiSettings;

        if ($uiSettings !== null && $uiSettings->getUser() !== $this) {
            $uiSettings->setUser($this);
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
