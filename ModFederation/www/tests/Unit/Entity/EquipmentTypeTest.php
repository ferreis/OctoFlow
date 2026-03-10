<?php

namespace App\Tests\Unit\Entity;

use App\Entity\Equipment;
use App\Entity\EquipmentType;
use PHPUnit\Framework\TestCase;

class EquipmentTypeTest extends TestCase
{
    private EquipmentType $equipmentType;

    protected function setUp(): void
    {
        $this->equipmentType = new EquipmentType();
    }

    /**
     * Testa os métodos getters e setters básicos da entidade EquipmentType
     * (name, serviceName)
     */
    public function testGettersAndSetters(): void
    {
        $this->equipmentType->setName('Mareograph');
        $this->assertEquals('Mareograph', $this->equipmentType->getName());

        $this->equipmentType->setServiceName('tidegraph_service');
        $this->assertEquals('tidegraph_service', $this->equipmentType->getServiceName());
    }

    /**
     * Verifica se as definições de atributos podem ser armazenadas e recuperadas
     */
    public function testGetAttributeDefinitions(): void
    {
        $definitions = [
            ['name' => 'latitude', 'type' => 'float'],
            ['name' => 'longitude', 'type' => 'float'],
        ];

        $this->equipmentType->setAttributeDefinitions($definitions);

        $this->assertEquals($definitions, $this->equipmentType->getAttributeDefinitions());
    }

    /**
     * Verifica se o método getAttributeNames extrai corretamente os nomes
     * dos atributos a partir das definições
     */
    public function testGetAttributeNamesExtractsNamesFromDefinitions(): void
    {
        $definitions = [
            ['name' => 'latitude', 'type' => 'float'],
            ['name' => 'longitude', 'type' => 'float'],
            ['name' => 'altitude', 'type' => 'float'],
        ];

        $this->equipmentType->setAttributeDefinitions($definitions);

        $names = $this->equipmentType->getAttributeNames();

        $this->assertCount(3, $names);
        $this->assertContains('latitude', $names);
        $this->assertContains('longitude', $names);
        $this->assertContains('altitude', $names);
    }

    /**
     * Verifica se getAttributeNames retorna array vazio quando não há definições
     */
    public function testGetAttributeNamesWithEmptyDefinitions(): void
    {
        $this->equipmentType->setAttributeDefinitions([]);

        $names = $this->equipmentType->getAttributeNames();

        $this->assertCount(0, $names);
        $this->assertEquals([], $names);
    }

    /**
     * Testa se os métodos retornam a instância da classe (fluent interface)
     * permitindo encadeamento de chamadas
     */
    public function testFluentInterface(): void
    {
        $result = $this->equipmentType
            ->setName('Test Type')
            ->setServiceName('test_service')
            ->setAttributeDefinitions([]);

        $this->assertInstanceOf(EquipmentType::class, $result);
    }

    /**
     * Verifica se getEquipments retorna uma Collection vazia ao criar nova instância
     */
    public function testGetEquipmentsReturnsCollection(): void
    {
        $equipments = $this->equipmentType->getEquipments();

        $this->assertInstanceOf(\Doctrine\Common\Collections\Collection::class, $equipments);
        $this->assertCount(0, $equipments);
    }
}
