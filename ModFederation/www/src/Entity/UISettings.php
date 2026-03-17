<?php

namespace App\Entity;

use App\Repository\UISettingsRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: UISettingsRepository::class)]
#[ORM\Table(name: 'ui_settings')]
class UISettings
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(inversedBy: 'uiSettings')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE', unique: true)]
    private ?User $user = null;

    #[ORM\Column(length: 64, options: ['default' => 'original'])]
    private string $themeKey = 'original';

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

        if ($user !== null && $user->getUiSettings() !== $this) {
            $user->setUiSettings($this);
        }

        return $this;
    }

    public function getThemeKey(): string
    {
        return $this->themeKey;
    }

    public function setThemeKey(string $themeKey): self
    {
        $normalizedThemeKey = strtolower(trim($themeKey));
        $this->themeKey = $normalizedThemeKey !== '' ? $normalizedThemeKey : 'original';
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

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
