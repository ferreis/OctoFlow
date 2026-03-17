<?php

namespace App\Tests\Unit\Entity;

use App\Entity\Equipment;
use App\Entity\EquipmentType;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

class EquipmentTest extends TestCase
{
    private Equipment $equipment;

    protected function setUp(): void
    {
        $this->equipment = new Equipment();
    }

    /**
     * Testa os métodos getters e setters básicos da entidade Equipment
     * (name, description, protocol)
     */
    public function testGettersAndSetters(): void
    {
        $this->equipment->setName('Test Equipment');
        $this->assertEquals('Test Equipment', $this->equipment->getName());

        $this->equipment->setDescription('Test Description');
        $this->assertEquals('Test Description', $this->equipment->getDescription());

        $this->equipment->setProtocol('HTTP');
        $this->assertEquals('HTTP', $this->equipment->getProtocol());
    }

    /**
     * Verifica se o keyName é automaticamente convertido para minúsculas
     */
    public function testKeyNameIsConvertedToLowerCase(): void
    {
        $this->equipment->setKeyName('TEST_KEY');
        $this->assertEquals('test_key', $this->equipment->getKeyName());
    }

    /**
     * Verifica se o keyName tem espaços em branco removidos automaticamente
     */
    public function testKeyNameIsTrimmed(): void
    {
        $this->equipment->setKeyName('  test_key  ');
        $this->assertEquals('test_key', $this->equipment->getKeyName());
    }

    /**
     * Verifica se a flag isReadingEnabled tem valor padrão true
     */
    public function testIsReadingEnabledDefaultsToTrue(): void
    {
        $this->assertTrue($this->equipment->getIsReadingEnabled());
    }

    /**
     * Testa a alteração do valor da flag isReadingEnabled
     */
    public function testSetIsReadingEnabled(): void
    {
        $this->equipment->setIsReadingEnabled(false);
        $this->assertFalse($this->equipment->getIsReadingEnabled());

        $this->equipment->setIsReadingEnabled(true);
        $this->assertTrue($this->equipment->getIsReadingEnabled());
    }

    /**
     * Verifica se a flag isNotificationsEnabled tem valor padrão true
     */
    public function testIsNotificationsEnabledDefaultsToTrue(): void
    {
        $this->assertTrue($this->equipment->getIsNotificationsEnabled());
    }

    /**
     * Testa a alteração do valor da flag isNotificationsEnabled
     */
    public function testSetIsNotificationsEnabled(): void
    {
        $this->equipment->setIsNotificationsEnabled(false);
        $this->assertFalse($this->equipment->getIsNotificationsEnabled());

        $this->equipment->setIsNotificationsEnabled(true);
        $this->assertTrue($this->equipment->getIsNotificationsEnabled());
    }

    /**
     * Verifica se é possível associar um EquipmentType ao Equipment
     */
    public function testSetEquipmentType(): void
    {
        $equipmentType = $this->createMock(EquipmentType::class);
        $equipmentType->method('getAttributeNames')->willReturn([]);

        $this->equipment->setEquipmentType($equipmentType);

        $this->assertSame($equipmentType, $this->equipment->getEquipmentType());
    }

    /**
     * Verifica se é possível remover a associação do EquipmentType (setando null)
     */
    public function testSetEquipmentTypeToNull(): void
    {
        $equipmentType = $this->createMock(EquipmentType::class);
        $equipmentType->method('getAttributeNames')->willReturn([]);

        $this->equipment->setEquipmentType($equipmentType);
        $this->assertNotNull($this->equipment->getEquipmentType());

        $this->equipment->setEquipmentType(null);
        $this->assertNull($this->equipment->getEquipmentType());
    }
}
