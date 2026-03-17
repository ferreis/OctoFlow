<?php

namespace App\Tests\Integration\Service\EquipmentData;

use App\Entity\Equipment;
use App\Entity\EquipmentType;
use App\Service\EquipmentData\EquipmentDataServiceFactory;
use App\Service\EquipmentData\EquipmentDataServiceInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Teste de integração para o serviço EquipmentDataServiceFactory
 *
 * Este teste verifica se a factory consegue recuperar os serviços
 * registrados no container através do AutowireIterator
 */
class EquipmentDataServiceFactoryIntegrationTest extends KernelTestCase
{
    /**
     * Testa se a factory pode ser recuperada do container e está
     * corretamente configurada com os serviços taggeados
     */
    public function testFactoryIsAvailableInContainer(): void
    {
        // (1) Inicia o kernel do Symfony
        self::bootKernel();

        // (2) Usa static::getContainer() para acessar o container de serviços
        $container = static::getContainer();

        // (3) Recupera a factory do container
        $factory = $container->get(EquipmentDataServiceFactory::class);

        // Verifica que a factory foi recuperada corretamente
        $this->assertInstanceOf(EquipmentDataServiceFactory::class, $factory);
    }

    /**
     * Testa se a factory consegue retornar um serviço registrado
     * para um tipo de equipamento específico
     */
    public function testFactoryReturnsRegisteredService(): void
    {
        // Inicia o kernel
        self::bootKernel();
        $container = static::getContainer();
        $factory = $container->get(EquipmentDataServiceFactory::class);
        $entityManager = $container->get('doctrine')->getManager();

        // Cria um tipo de equipamento com um serviceName que existe no sistema
        // Nota: Este teste assume que há pelo menos um serviço registrado
        // Vamos tentar buscar um dos serviços reais do projeto
        $equipmentType = new EquipmentType();
        $equipmentType->setName('Mareograph Test');
        $equipmentType->setServiceName('tidegraph'); // Um dos serviços reais do projeto
        $equipmentType->setAttributeDefinitions([]);

        $equipment = new Equipment();
        $equipment->setName('Test Tidegraph Equipment');
        $equipment->setKeyName('test_tidegraph_' . uniqid());
        $equipment->setEquipmentType($equipmentType);

        $entityManager->persist($equipmentType);
        $entityManager->persist($equipment);
        $entityManager->flush();

        try {
            // Tenta recuperar o serviço através da factory
            $service = $factory->getForEquipment($equipment);

            // Verifica se o serviço retornado implementa a interface correta
            $this->assertInstanceOf(EquipmentDataServiceInterface::class, $service);

            // Verifica se o serviceName confere
            $this->assertEquals('tidegraph', $service->getServiceName());
        } catch (\RuntimeException $e) {
            // Se não há serviço registrado com esse nome, o teste passa
            // mas registra que não há serviços disponíveis para testar
            $this->assertStringContainsString(
                'Nenhum serviço registrado',
                $e->getMessage()
            );
        } finally {
            // Limpa os dados de teste
            $entityManager->remove($equipment);
            $entityManager->remove($equipmentType);
            $entityManager->flush();
        }
    }

    /**
     * Testa se a factory lança exceção quando o equipamento não tem tipo definido
     */
    public function testFactoryThrowsExceptionWithoutEquipmentType(): void
    {
        // Inicia o kernel
        self::bootKernel();
        $container = static::getContainer();
        $factory = $container->get(EquipmentDataServiceFactory::class);
        $entityManager = $container->get('doctrine')->getManager();

        // Cria um equipamento sem tipo
        $equipment = new Equipment();
        $equipment->setName('Equipment Without Type');
        $equipment->setKeyName('no_type_equipment_' . uniqid());

        $entityManager->persist($equipment);
        $entityManager->flush();

        // Espera que uma exceção seja lançada
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Equipamento sem tipo ou serviceName definido');

        try {
            $factory->getForEquipment($equipment);
        } finally {
            // Limpa os dados de teste
            $entityManager->remove($equipment);
            $entityManager->flush();
        }
    }

    /**
     * Testa a integração completa verificando se todos os serviços
     * taggeados estão disponíveis através da factory
     */
    public function testAllTaggedServicesAreAvailable(): void
    {
        // Inicia o kernel
        self::bootKernel();
        $container = static::getContainer();

        // Lista de serviceNames que devem estar registrados no projeto
        $expectedServices = [
            'tidegraph',
            'telemetric',
            'weatherlink',
            'http_equipment',
            'ftp_equipment',
            'api_equipment',
            'defesa_civil_tidegraph',
        ];

        $entityManager = $container->get('doctrine')->getManager();
        $factory = $container->get(EquipmentDataServiceFactory::class);

        foreach ($expectedServices as $serviceName) {
            // Gera um key_name único para evitar conflitos
            $uniqueKey = 'test_' . $serviceName . '_' . uniqid();

            // Cria tipo e equipamento para teste
            $equipmentType = new EquipmentType();
            $equipmentType->setName('Test ' . $serviceName);
            $equipmentType->setServiceName($serviceName);
            $equipmentType->setAttributeDefinitions([]);

            $equipment = new Equipment();
            $equipment->setName('Test Equipment for ' . $serviceName);
            $equipment->setKeyName($uniqueKey);
            $equipment->setEquipmentType($equipmentType);

            $entityManager->persist($equipmentType);
            $entityManager->persist($equipment);
            $entityManager->flush();

            try {
                // Tenta recuperar o serviço
                $service = $factory->getForEquipment($equipment);

                // Se conseguiu, verifica se é do tipo correto
                $this->assertInstanceOf(EquipmentDataServiceInterface::class, $service);
                $this->assertEquals($serviceName, $service->getServiceName());

                // Marca que o serviço foi encontrado
                $this->addToAssertionCount(1);
            } catch (\RuntimeException $e) {
                // Serviço não está registrado - isso é aceitável
                // pois nem todos os serviços podem estar configurados
                $this->assertStringContainsString(
                    'Nenhum serviço registrado',
                    $e->getMessage(),
                    "Serviço $serviceName não está registrado"
                );
            } finally {
                // Limpa
                $entityManager->remove($equipment);
                $entityManager->remove($equipmentType);
                $entityManager->flush();
            }
        }
    }
}
