<?php

namespace App\Tests\Unit\Service\EquipmentData;

use App\Entity\Equipment;
use App\Service\EquipmentData\EquipmentTableNameResolver;
use PHPUnit\Framework\TestCase;

class EquipmentTableNameResolverTest extends TestCase
{
    private EquipmentTableNameResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new EquipmentTableNameResolver();
    }

    /**
     * Testa a resolução do nome da tabela quando o equipamento tem um keyName válido
     */
    public function testResolveTableNameWithKeyName(): void
    {
        $equipment = $this->createMock(Equipment::class);
        $equipment->method('getKeyName')->willReturn('test_equipment');
        $result = $this->resolver->resolveTableName($equipment);
        $this->assertEquals('equipment_data_test_equipment', $result);
    }

    /**
     * Verifica se caracteres especiais no keyName são sanitizados corretamente
     * (removendo caracteres não alfanuméricos)
     */
    public function testResolveTableNameWithSpecialCharacters(): void
    {
        $equipment = $this->createMock(Equipment::class);
        $equipment->method('getKeyName')->willReturn('Test-Equipment@123');
        $result = $this->resolver->resolveTableName($equipment);
        $this->assertEquals('equipment_data_test_equipment_123', $result);
    }

    /**
     * Verifica se quando o keyName é null, o ID do equipamento é usado como fallback
     */
    public function testResolveTableNameWithNullKeyNameUsesId(): void
    {
        $equipment = $this->createMock(Equipment::class);
        $equipment->method('getKeyName')->willReturn(null);
        $equipment->method('getId')->willReturn(42);
        $result = $this->resolver->resolveTableName($equipment);
        $this->assertEquals('equipment_data_42', $result);
    }

    /**
     * Testa a resolução do nome da tabela de dados brutos (raw data)
     */
    public function testResolveRawTableNameWithKeyName(): void
    {
        $equipment = $this->createMock(Equipment::class);
        $equipment->method('getKeyName')->willReturn('test_equipment');
        $result = $this->resolver->resolveRawTableName($equipment);
        $this->assertEquals('equipment_raw_data_test_equipment', $result);
    }
}
