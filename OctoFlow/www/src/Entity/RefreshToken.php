<?php

namespace App\Entity;

use App\Repository\RefreshTokenRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: RefreshTokenRepository::class)]
#[ORM\Table(name: 'refresh_token')]
#[ORM\Index(columns: ['expires_at'], name: 'idx_refresh_token_expires')]
#[ORM\Index(columns: ['user_id'], name: 'idx_refresh_token_user')]
#[ORM\Index(columns: ['token_family_id'], name: 'idx_refresh_token_family')]
#[ORM\Index(columns: ['fingerprint_hash'], name: 'idx_refresh_token_fingerprint')]
class RefreshToken
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'refreshTokens')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\Column(length: 64, unique: true)]
    private string $tokenHash = '';

    #[ORM\Column(length: 64)]
    private string $fingerprintHash = '';

    #[ORM\Column(length: 64)]
    private string $userAgentHash = '';

    #[ORM\Column(length: 64)]
    private string $ipHash = '';

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $tokenFamilyId = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $parentTokenHash = null;

    #[ORM\Column]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $contextChangedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $reuseDetectedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->expiresAt = new \DateTimeImmutable('+30 days');
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): self
    {
        $this->user = $user;

        return $this;
    }

    public function getTokenHash(): string
    {
        return $this->tokenHash;
    }

    public function setTokenHash(string $tokenHash): self
    {
        $this->tokenHash = $tokenHash;

        return $this;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(\DateTimeImmutable $expiresAt): self
    {
        $this->expiresAt = $expiresAt;

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

    public function getRevokedAt(): ?\DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function setRevokedAt(?\DateTimeImmutable $revokedAt): self
    {
        $this->revokedAt = $revokedAt;

        return $this;
    }

    public function isRevoked(): bool
    {
        return $this->revokedAt !== null;
    }

    public function isExpired(\DateTimeImmutable $reference): bool
    {
        return $this->expiresAt <= $reference;
    }

    public function getFingerprintHash(): string
    {
        return $this->fingerprintHash;
    }

    public function setFingerprintHash(string $fingerprintHash): self
    {
        $this->fingerprintHash = $fingerprintHash;

        return $this;
    }

    public function getUserAgentHash(): string
    {
        return $this->userAgentHash;
    }

    public function setUserAgentHash(string $userAgentHash): self
    {
        $this->userAgentHash = $userAgentHash;

        return $this;
    }

    public function getIpHash(): string
    {
        return $this->ipHash;
    }

    public function setIpHash(string $ipHash): self
    {
        $this->ipHash = $ipHash;

        return $this;
    }

    public function getTokenFamilyId(): ?string
    {
        return $this->tokenFamilyId;
    }

    public function setTokenFamilyId(?string $tokenFamilyId): self
    {
        $this->tokenFamilyId = $tokenFamilyId;

        return $this;
    }

    public function getParentTokenHash(): ?string
    {
        return $this->parentTokenHash;
    }

    public function setParentTokenHash(?string $parentTokenHash): self
    {
        $this->parentTokenHash = $parentTokenHash;

        return $this;
    }

    public function getContextChangedAt(): ?\DateTimeImmutable
    {
        return $this->contextChangedAt;
    }

    public function setContextChangedAt(?\DateTimeImmutable $contextChangedAt): self
    {
        $this->contextChangedAt = $contextChangedAt;

        return $this;
    }

    public function getReuseDetectedAt(): ?\DateTimeImmutable
    {
        return $this->reuseDetectedAt;
    }

    public function setReuseDetectedAt(?\DateTimeImmutable $reuseDetectedAt): self
    {
        $this->reuseDetectedAt = $reuseDetectedAt;

        return $this;
    }
}
