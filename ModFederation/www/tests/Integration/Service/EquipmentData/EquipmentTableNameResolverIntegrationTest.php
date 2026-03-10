<?php

namespace App\Tests\Integration\Service\EquipmentData;

use App\Entity\Equipment;
use App\Entity\EquipmentType;
use App\Service\EquipmentData\EquipmentTableNameResolver;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Teste de integração para o serviço EquipmentTableNameResolver
 *
 * Este teste utiliza o container real do Symfony para verificar
 * se o serviço está corretamente configurado e funcionando
 */
class EquipmentTableNameResolverIntegrationTest extends KernelTestCase
{
    /**
     * Testa se o serviço EquipmentTableNameResolver pode ser recuperado
     * do container de serviços e executado corretamente
     */
    public function testServiceIsAvailableInContainer(): void
    {
        // (1) Inicia o kernel do Symfony
        self::bootKernel();

        // (2) Usa static::getContainer() para acessar o container de serviços
        $container = static::getContainer();

        // (3) Recupera o serviço do container
        $resolver = $container->get(EquipmentTableNameResolver::class);

        // Verifica que o serviço foi recuperado corretamente
        $this->assertInstanceOf(EquipmentTableNameResolver::class, $resolver);
    }

    /**
     * Testa a funcionalidade completa do serviço resolvendo nomes de tabelas
     * para um equipamento real criado no banco de dados
     */
    public function testResolveTableNameWithRealEquipment(): void
    {
        // (1) Inicia o kernel do Symfony
        self::bootKernel();

        // (2) Acessa o container de serviços
        $container = static::getContainer();

        // (3) Recupera o serviço e o entity manager
        $resolver = $container->get(EquipmentTableNameResolver::class);
        $entityManager = $container->get('doctrine')->getManager();

        // Cria um equipamento de teste
        $uniqueId = uniqid();
        $equipmentType = new EquipmentType();
        $equipmentType->setName('Test Type');
        $equipmentType->setServiceName('test_service');
        $equipmentType->setAttributeDefinitions([]);

        $equipment = new Equipment();
        $equipment->setName('Test Equipment');
        $equipment->setKeyName('integration_test_equip_' . $uniqueId);
        $equipment->setEquipmentType($equipmentType);

        $entityManager->persist($equipmentType);
        $entityManager->persist($equipment);
        $entityManager->flush();

        // Testa o serviço com o equipamento real
        $tableName = $resolver->resolveTableName($equipment);
        $rawTableName = $resolver->resolveRawTableName($equipment);

        // Verifica os resultados
        $this->assertEquals('equipment_data_integration_test_equip_' . $uniqueId, $tableName);
        $this->assertEquals('equipment_raw_data_integration_test_equip_' . $uniqueId, $rawTableName);

        // Limpa os dados de teste
        $entityManager->remove($equipment);
        $entityManager->remove($equipmentType);
        $entityManager->flush();
    }

    /**
     * Testa o serviço com múltiplos equipamentos diferentes
     * verificando a consistência dos nomes gerados
     */
    public function testResolveTableNameConsistencyWithMultipleEquipments(): void
    {
        // Inicia o kernel
        self::bootKernel();
        $container = static::getContainer();
        $resolver = $container->get(EquipmentTableNameResolver::class);
        $entityManager = $container->get('doctrine')->getManager();

        // Cria tipo de equipamento
        $uniqueId = uniqid();
        $equipmentType = new EquipmentType();
        $equipmentType->setName('Test Type Multiple');
        $equipmentType->setServiceName('test_service');
        $equipmentType->setAttributeDefinitions([]);
        $entityManager->persist($equipmentType);

        $testCases = [
            'simple_name_' . $uniqueId => 'equipment_data_simple_name_' . $uniqueId,
            'NameWithSpecialChars_' . $uniqueId => 'equipment_data_namewithspecialchars_' . $uniqueId,
            'UPPERCASE_NAME_' . $uniqueId => 'equipment_data_uppercase_name_' . $uniqueId,
        ];

        foreach ($testCases as $keyName => $expectedTable) {
            $equipment = new Equipment();
            $equipment->setName('Test ' . $keyName);
            $equipment->setKeyName($keyName);
            $equipment->setEquipmentType($equipmentType);

            $entityManager->persist($equipment);
            $entityManager->flush();

            // Testa a resolução do nome
            $tableName = $resolver->resolveTableName($equipment);
            $this->assertEquals($expectedTable, $tableName);

            // Limpa
            $entityManager->remove($equipment);
        }

        $entityManager->remove($equipmentType);
        $entityManager->flush();
    }
}
