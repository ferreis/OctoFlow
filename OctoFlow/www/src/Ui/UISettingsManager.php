<?php

namespace App\Ui;

use App\Entity\UISettings;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

final class UISettingsManager
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function getOrCreate(User $user): UISettings
    {
        $existingSettings = $user->getUiSettings();
        if ($existingSettings instanceof UISettings) {
            return $existingSettings;
        }

        $settings = (new UISettings())
            ->setUser($user);

        $user->setUiSettings($settings);
        $this->entityManager->persist($settings);

        return $settings;
    }

    /**
     * @return array{
     *     themeKey: string,
     *     colorVisionMode: string,
     *     colorVisionIntensity: int,
     *     highContrastEnabled: bool,
     *     fontScale: string,
     *     layoutDensityMode: string,
     *     layoutDensityScale: int,
     *     customThemePalette: array<string, string>|null,
     *     updatedAt: string
     * }
     */
    public function buildPayload(UISettings $settings): array
    {
        return [
            'themeKey' => $settings->getThemeKey(),
            'colorVisionMode' => $settings->getColorVisionMode(),
            'colorVisionIntensity' => $settings->getColorVisionIntensity(),
            'highContrastEnabled' => $settings->isHighContrastEnabled(),
            'fontScale' => $settings->getFontScale(),
            'layoutDensityMode' => $settings->getLayoutDensityMode(),
            'layoutDensityScale' => $settings->getLayoutDensityScale(),
            'customThemePalette' => $settings->getCustomThemePalette(),
            'updatedAt' => $settings->getUpdatedAt()->format(\DATE_ATOM),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function updateFromPayload(UISettings $settings, array $payload): UISettings
    {
        if (array_key_exists('themeKey', $payload)) {
            $settings->setThemeKey((string) $payload['themeKey']);
        }

        if (array_key_exists('colorVisionMode', $payload)) {
            $settings->setColorVisionMode((string) $payload['colorVisionMode']);
        }

        if (array_key_exists('colorVisionIntensity', $payload)) {
            $settings->setColorVisionIntensity($this->normalizeIntensity($payload['colorVisionIntensity']));
        }

        if (array_key_exists('highContrastEnabled', $payload)) {
            $settings->setHighContrastEnabled($this->normalizeBoolean($payload['highContrastEnabled']));
        }

        if (array_key_exists('fontScale', $payload)) {
            $settings->setFontScale((string) $payload['fontScale']);
        }

        if (array_key_exists('layoutDensityMode', $payload)) {
            $settings->setLayoutDensityMode((string) $payload['layoutDensityMode']);
        }

        if (array_key_exists('layoutDensityScale', $payload)) {
            $settings->setLayoutDensityScale($this->normalizeLayoutDensityScale($payload['layoutDensityScale']));
        }

        if (array_key_exists('customThemePalette', $payload)) {
            $settings->setCustomThemePalette($this->normalizeCustomThemePalette($payload['customThemePalette']));
        }

        return $settings;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function normalizeCustomThemePalette(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }

        if (!is_array($value)) {
            throw new \InvalidArgumentException('The custom theme palette payload is invalid.');
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    private function normalizeIntensity(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            return (int) round($value);
        }

        if (is_string($value) && is_numeric(trim($value))) {
            return (int) round((float) trim($value));
        }

        throw new \InvalidArgumentException('The color vision intensity must be a number between 0 and 100.');
    }

    private function normalizeLayoutDensityScale(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            return (int) round($value);
        }

        if (is_string($value) && is_numeric(trim($value))) {
            return (int) round((float) trim($value));
        }

        throw new \InvalidArgumentException('The layout density scale must be a number between 70 and 110.');
    }

    private function normalizeBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (int) $value !== 0;
        }

        if (is_string($value)) {
            $normalizedValue = strtolower(trim($value));

            if (in_array($normalizedValue, ['1', 'true', 'yes', 'on', 'enabled'], true)) {
                return true;
            }

            if (in_array($normalizedValue, ['0', 'false', 'no', 'off', 'disabled', ''], true)) {
                return false;
            }
        }

        throw new \InvalidArgumentException('The high contrast flag is invalid.');
    }
}
