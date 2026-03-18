<?php

namespace App\Entity;

use App\Repository\UISettingsRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: UISettingsRepository::class)]
#[ORM\Table(name: 'ui_settings')]
class UISettings
{
    public const COLOR_VISION_MODE_NONE = 'none';
    public const FONT_SCALE_DEFAULT = 'default';

    /**
     * @var list<string>
     */
    public const ALLOWED_COLOR_VISION_MODES = [
        self::COLOR_VISION_MODE_NONE,
        'protanopia',
        'deuteranopia',
        'tritanopia',
        'acromatopsia',
    ];

    /**
     * @var list<string>
     */
    public const ALLOWED_FONT_SCALES = [
        self::FONT_SCALE_DEFAULT,
        'medium',
        'large',
        'extra-large',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(inversedBy: 'uiSettings')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE', unique: true)]
    private ?User $user = null;

    #[ORM\Column(length: 64, options: ['default' => 'original'])]
    private string $themeKey = 'original';

    #[ORM\Column(length: 32, options: ['default' => self::COLOR_VISION_MODE_NONE])]
    private string $colorVisionMode = self::COLOR_VISION_MODE_NONE;

    #[ORM\Column(options: ['default' => 100])]
    private int $colorVisionIntensity = 100;

    #[ORM\Column(options: ['default' => false])]
    private bool $highContrastEnabled = false;

    #[ORM\Column(length: 32, options: ['default' => self::FONT_SCALE_DEFAULT])]
    private string $fontScale = self::FONT_SCALE_DEFAULT;

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

    public function getColorVisionMode(): string
    {
        return $this->colorVisionMode;
    }

    public function setColorVisionMode(string $colorVisionMode): self
    {
        $normalizedMode = strtolower(trim($colorVisionMode));
        if (!in_array($normalizedMode, self::ALLOWED_COLOR_VISION_MODES, true)) {
            throw new \InvalidArgumentException('The selected color vision mode is invalid.');
        }

        $this->colorVisionMode = $normalizedMode;
        $this->touch();

        return $this;
    }

    public function getColorVisionIntensity(): int
    {
        return $this->colorVisionIntensity;
    }

    public function setColorVisionIntensity(int $colorVisionIntensity): self
    {
        $this->colorVisionIntensity = min(100, max(0, $colorVisionIntensity));
        $this->touch();

        return $this;
    }

    public function isHighContrastEnabled(): bool
    {
        return $this->highContrastEnabled;
    }

    public function setHighContrastEnabled(bool $highContrastEnabled): self
    {
        $this->highContrastEnabled = $highContrastEnabled;
        $this->touch();

        return $this;
    }

    public function getFontScale(): string
    {
        return $this->fontScale;
    }

    public function setFontScale(string $fontScale): self
    {
        $normalizedScale = strtolower(trim($fontScale));
        if (!in_array($normalizedScale, self::ALLOWED_FONT_SCALES, true)) {
            throw new \InvalidArgumentException('The selected font scale is invalid.');
        }

        $this->fontScale = $normalizedScale;
        $this->touch();

        return $this;
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
