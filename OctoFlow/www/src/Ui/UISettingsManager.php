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
     * @return array{themeKey: string, updatedAt: string}
     */
    public function buildPayload(UISettings $settings): array
    {
        return [
            'themeKey' => $settings->getThemeKey(),
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

        return $settings;
    }
}
