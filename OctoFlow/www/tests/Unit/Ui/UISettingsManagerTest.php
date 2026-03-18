<?php

namespace App\Tests\Unit\Ui;

use App\Entity\UISettings;
use App\Ui\UISettingsManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class UISettingsManagerTest extends TestCase
{
    public function testBuildPayloadIncludesAccessibilityFields(): void
    {
        $manager = new UISettingsManager($this->createMock(EntityManagerInterface::class));
        $settings = (new UISettings())
            ->setThemeKey('dark-premium')
            ->setColorVisionMode('deuteranopia')
            ->setColorVisionIntensity(73)
            ->setHighContrastEnabled(true)
            ->setFontScale('large');

        $payload = $manager->buildPayload($settings);

        $this->assertSame('dark-premium', $payload['themeKey']);
        $this->assertSame('deuteranopia', $payload['colorVisionMode']);
        $this->assertSame(73, $payload['colorVisionIntensity']);
        $this->assertTrue($payload['highContrastEnabled']);
        $this->assertSame('large', $payload['fontScale']);
        $this->assertArrayHasKey('updatedAt', $payload);
    }

    public function testUpdateFromPayloadNormalizesAccessibilityFields(): void
    {
        $manager = new UISettingsManager($this->createMock(EntityManagerInterface::class));
        $settings = new UISettings();

        $manager->updateFromPayload($settings, [
            'themeKey' => ' neon-tech ',
            'colorVisionMode' => 'PROTANOPIA',
            'colorVisionIntensity' => '84',
            'highContrastEnabled' => 'true',
            'fontScale' => 'extra-large',
        ]);

        $this->assertSame('neon-tech', $settings->getThemeKey());
        $this->assertSame('protanopia', $settings->getColorVisionMode());
        $this->assertSame(84, $settings->getColorVisionIntensity());
        $this->assertTrue($settings->isHighContrastEnabled());
        $this->assertSame('extra-large', $settings->getFontScale());
    }

    public function testUpdateFromPayloadRejectsInvalidAccessibilityMode(): void
    {
        $manager = new UISettingsManager($this->createMock(EntityManagerInterface::class));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The selected color vision mode is invalid.');

        $manager->updateFromPayload(new UISettings(), [
            'colorVisionMode' => 'sepia',
        ]);
    }
}
