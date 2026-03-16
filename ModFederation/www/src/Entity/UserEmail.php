<?php

namespace App\Entity;

use App\Repository\UserEmailRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: UserEmailRepository::class)]
#[ORM\Table(name: 'user_email')]
class UserEmail
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'emailAddresses')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\Column(length: 180, unique: true)]
    private string $email = '';

    /**
     * @var list<string>
     */
    #[ORM\Column(type: 'json')]
    private array $providers = [];

    #[ORM\Column(options: ['default' => false])]
    private bool $isPrimary = false;

    #[ORM\Column(options: ['default' => true])]
    private bool $isVerified = true;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

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

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): self
    {
        $this->user = $user;
        $this->touch();

        return $this;
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

    /**
     * @return list<string>
     */
    public function getProviders(): array
    {
        return $this->providers;
    }

    /**
     * @param list<string> $providers
     */
    public function setProviders(array $providers): self
    {
        $this->providers = $this->normalizeProviders($providers);
        $this->touch();

        return $this;
    }

    public function addProvider(string $provider): self
    {
        $providers = $this->providers;
        $providers[] = $provider;
        $this->providers = $this->normalizeProviders($providers);
        $this->touch();

        return $this;
    }

    public function hasProvider(string $provider): bool
    {
        return in_array($this->normalizeProvider($provider), $this->providers, true);
    }

    public function isPrimary(): bool
    {
        return $this->isPrimary;
    }

    public function setIsPrimary(bool $isPrimary): self
    {
        $this->isPrimary = $isPrimary;
        $this->touch();

        return $this;
    }

    public function isVerified(): bool
    {
        return $this->isVerified;
    }

    public function setIsVerified(bool $isVerified): self
    {
        $this->isVerified = $isVerified;
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

    /**
     * @param list<string> $providers
     *
     * @return list<string>
     */
    private function normalizeProviders(array $providers): array
    {
        $normalized = array_map(fn (string $provider): string => $this->normalizeProvider($provider), $providers);
        $filtered = array_values(array_filter($normalized, static fn (string $provider): bool => $provider !== ''));
        $unique = array_values(array_unique($filtered));

        usort($unique, static function (string $left, string $right): int {
            $priority = [
                'system' => 0,
                'google' => 1,
                'github' => 2,
            ];

            $leftPriority = $priority[$left] ?? 99;
            $rightPriority = $priority[$right] ?? 99;

            if ($leftPriority === $rightPriority) {
                return strcmp($left, $right);
            }

            return $leftPriority <=> $rightPriority;
        });

        return $unique;
    }

    private function normalizeProvider(string $provider): string
    {
        return strtolower(trim($provider));
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
