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
            ->setFontScale('large')
            ->setCustomThemePalette([
                'primary' => '#112233',
                'secondary' => '#223344',
                'accent' => '#334455',
                'bg' => '#445566',
                'text' => '#f8fafc',
            ]);

        $payload = $manager->buildPayload($settings);

        $this->assertSame('dark-premium', $payload['themeKey']);
        $this->assertSame('deuteranopia', $payload['colorVisionMode']);
        $this->assertSame(73, $payload['colorVisionIntensity']);
        $this->assertTrue($payload['highContrastEnabled']);
        $this->assertSame('large', $payload['fontScale']);
        $this->assertSame([
            'primary' => '#112233',
            'secondary' => '#223344',
            'accent' => '#334455',
            'bg' => '#445566',
            'text' => '#f8fafc',
        ], $payload['customThemePalette']);
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
            'customThemePalette' => [
                'primary' => '#123456',
                'secondary' => '#abcdef',
                'accent' => '#789abc',
                'bg' => '#0f172a',
                'text' => '#ffffff',
            ],
        ]);

        $this->assertSame('neon-tech', $settings->getThemeKey());
        $this->assertSame('protanopia', $settings->getColorVisionMode());
        $this->assertSame(84, $settings->getColorVisionIntensity());
        $this->assertTrue($settings->isHighContrastEnabled());
        $this->assertSame('extra-large', $settings->getFontScale());
        $this->assertSame([
            'primary' => '#123456',
            'secondary' => '#abcdef',
            'accent' => '#789abc',
            'bg' => '#0f172a',
            'text' => '#ffffff',
        ], $settings->getCustomThemePalette());
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

    public function testUpdateFromPayloadRejectsInvalidCustomThemePaletteShape(): void
    {
        $manager = new UISettingsManager($this->createMock(EntityManagerInterface::class));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The custom theme palette must contain exactly: primary, secondary, accent, bg and text.');

        $manager->updateFromPayload(new UISettings(), [
            'customThemePalette' => [
                'primary' => '#123456',
                'secondary' => '#abcdef',
                'accent' => '#789abc',
                'bg' => '#0f172a',
            ],
        ]);
    }

    public function testUpdateFromPayloadRejectsInvalidCustomThemeColor(): void
    {
        $manager = new UISettingsManager($this->createMock(EntityManagerInterface::class));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The custom theme color "accent" must be a valid HEX color.');

        $manager->updateFromPayload(new UISettings(), [
            'customThemePalette' => [
                'primary' => '#123456',
                'secondary' => '#abcdef',
                'accent' => 'not-a-color',
                'bg' => '#0f172a',
                'text' => '#ffffff',
            ],
        ]);
    }
}
