<?php

namespace App\Tests\Integration\Repository;

use App\Entity\Equipment;
use App\Entity\EquipmentType;
use App\Entity\User;
use App\Repository\EquipmentRepository;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Teste de integração para o EquipmentRepository
 *
 * Este teste verifica operações complexas do repositório incluindo
 * relacionamentos com EquipmentType e User
 */
class EquipmentRepositoryIntegrationTest extends KernelTestCase
{
    private EquipmentRepository $repository;
    private $entityManager;

    protected function setUp(): void
    {
        // (1) Inicia o kernel do Symfony
        self::bootKernel();

        // (2) Recupera os serviços do container
        $container = static::getContainer();
        $this->repository = $container->get(EquipmentRepository::class);
        $this->entityManager = $container->get('doctrine')->getManager();
    }

    /**
     * Testa se o repositório está disponível no container
     */
    public function testRepositoryIsAvailableInContainer(): void
    {
        $this->assertInstanceOf(EquipmentRepository::class, $this->repository);
    }

    /**
     * Testa a criação e persistência de um equipamento com tipo
     */
    public function testCreateEquipmentWithType(): void
    {
        // Cria um tipo de equipamento
        $equipmentType = new EquipmentType();
        $equipmentType->setName('Temperature Sensor');
        $equipmentType->setServiceName('temperature_service');
        $equipmentType->setAttributeDefinitions([
            ['name' => 'latitude', 'type' => 'float'],
            ['name' => 'longitude', 'type' => 'float'],
        ]);

        // Cria o equipamento
        $keyName = 'sensor_alpha_' . uniqid();
        $equipment = new Equipment();
        $equipment->setName('Sensor Alpha');
        $equipment->setKeyName($keyName);
        $equipment->setDescription('Test temperature sensor');
        $equipment->setProtocol('HTTP');
        $equipment->setEquipmentType($equipmentType);

        // Persiste ambos
        $this->entityManager->persist($equipmentType);
        $this->entityManager->persist($equipment);
        $this->entityManager->flush();

        // Verifica se foram salvos
        $this->assertNotNull($equipment->getId());
        $this->assertNotNull($equipmentType->getId());

        // Busca o equipamento pelo repositório
        $foundEquipment = $this->repository->find($equipment->getId());

        // Verifica os dados
        $this->assertNotNull($foundEquipment);
        $this->assertEquals('Sensor Alpha', $foundEquipment->getName());
        $this->assertEquals($keyName, $foundEquipment->getKeyName());
        $this->assertNotNull($foundEquipment->getEquipmentType());
        $this->assertEquals('Temperature Sensor', $foundEquipment->getEquipmentType()->getName());

        // Limpa
        $this->entityManager->remove($equipment);
        $this->entityManager->remove($equipmentType);
        $this->entityManager->flush();
    }

    /**
     * Testa a busca de equipamentos por keyName
     */
    public function testFindEquipmentByKeyName(): void
    {
        // Cria tipo e equipamento
        $equipmentType = new EquipmentType();
        $equipmentType->setName('Test Type');
        $equipmentType->setServiceName('test_service');
        $equipmentType->setAttributeDefinitions([]);

        $keyName = 'unique_test_key_' . uniqid();
        $equipment = new Equipment();
        $equipment->setName('Unique Equipment');
        $equipment->setKeyName($keyName);
        $equipment->setEquipmentType($equipmentType);

        $this->entityManager->persist($equipmentType);
        $this->entityManager->persist($equipment);
        $this->entityManager->flush();

        // Busca por keyName
        $foundEquipment = $this->repository->findOneBy(['keyName' => $keyName]);

        // Verifica
        $this->assertNotNull($foundEquipment);
        $this->assertEquals($keyName, $foundEquipment->getKeyName());
        $this->assertEquals('Unique Equipment', $foundEquipment->getName());

        // Limpa
        $this->entityManager->remove($equipment);
        $this->entityManager->remove($equipmentType);
        $this->entityManager->flush();
    }

    /**
     * Testa o relacionamento Many-to-Many entre Equipment e User (userAccess)
     */
    public function testEquipmentUserAccessRelationship(): void
    {
        // Cria usuários
        $uniqueId = uniqid();
        $user1 = new User();
        $user1->setName('User One');
        $user1->setEmail('user1.access_' . $uniqueId . '@example.com');
        $user1->setPassword('password');

        $user2 = new User();
        $user2->setName('User Two');
        $user2->setEmail('user2.access_' . $uniqueId . '@example.com');
        $user2->setPassword('password');

        // Cria tipo e equipamento
        $equipmentType = new EquipmentType();
        $equipmentType->setName('Access Test Type');
        $equipmentType->setServiceName('access_service');
        $equipmentType->setAttributeDefinitions([]);

        $equipment = new Equipment();
        $equipment->setName('Access Test Equipment');
        $equipment->setKeyName('access_test_equip_' . $uniqueId);
        $equipment->setEquipmentType($equipmentType);
        $equipment->addUserAccess($user1);
        $equipment->addUserAccess($user2);

        // Persiste tudo
        $this->entityManager->persist($user1);
        $this->entityManager->persist($user2);
        $this->entityManager->persist($equipmentType);
        $this->entityManager->persist($equipment);
        $this->entityManager->flush();

        // Limpa o entity manager e recarrega
        $equipmentId = $equipment->getId();
        $this->entityManager->clear();

        // Busca novamente o equipamento
        $reloadedEquipment = $this->repository->find($equipmentId);

        // Verifica o relacionamento
        $this->assertCount(2, $reloadedEquipment->getUserAccess());

        $userEmails = [];
        foreach ($reloadedEquipment->getUserAccess() as $user) {
            $userEmails[] = $user->getEmail();
        }

        $this->assertContains('user1.access_' . $uniqueId . '@example.com', $userEmails);
        $this->assertContains('user2.access_' . $uniqueId . '@example.com', $userEmails);

        // Limpa - recarrega todas as entidades antes de remover
        $equipmentType = $this->entityManager->find(EquipmentType::class, $equipmentType->getId());
        $user1 = $this->entityManager->getRepository(User::class)->findOneBy(['email' => 'user1.access_' . $uniqueId . '@example.com']);
        $user2 = $this->entityManager->getRepository(User::class)->findOneBy(['email' => 'user2.access_' . $uniqueId . '@example.com']);

        // Remove na ordem correta: limpa a coleção de userAccess primeiro
        $reloadedEquipment->getUserAccess()->clear();
        $this->entityManager->flush();

        $this->entityManager->remove($reloadedEquipment);
        $this->entityManager->flush();

        if ($equipmentType) $this->entityManager->remove($equipmentType);
        if ($user1) $this->entityManager->remove($user1);
        if ($user2) $this->entityManager->remove($user2);
        $this->entityManager->flush();
    }

    /**
     * Testa a filtragem de equipamentos por flags (isReadingEnabled, isNotificationsEnabled)
     */
    public function testFindEquipmentsByFlags(): void
    {
        $equipmentType = new EquipmentType();
        $equipmentType->setName('Flag Test Type');
        $equipmentType->setServiceName('flag_service');
        $equipmentType->setAttributeDefinitions([]);
        $this->entityManager->persist($equipmentType);

        // Cria equipamento com leitura habilitada
        $uniqueId = uniqid();
        $enabledEquipment = new Equipment();
        $enabledEquipment->setName('Reading Enabled Equipment');
        $enabledEquipment->setKeyName('enabled_reading_' . $uniqueId);
        $enabledEquipment->setEquipmentType($equipmentType);
        $enabledEquipment->setIsReadingEnabled(true);

        // Cria equipamento com leitura desabilitada
        $disabledEquipment = new Equipment();
        $disabledEquipment->setName('Reading Disabled Equipment');
        $disabledEquipment->setKeyName('disabled_reading_' . $uniqueId);
        $disabledEquipment->setEquipmentType($equipmentType);
        $disabledEquipment->setIsReadingEnabled(false);

        $this->entityManager->persist($enabledEquipment);
        $this->entityManager->persist($disabledEquipment);
        $this->entityManager->flush();

        // Busca apenas equipamentos com leitura habilitada
        $enabledEquipments = $this->repository->findBy(['isReadingEnabled' => true]);
        $enabledKeys = array_map(fn($e) => $e->getKeyName(), $enabledEquipments);
        $this->assertContains('enabled_reading_' . $uniqueId, $enabledKeys);

        // Busca equipamentos com leitura desabilitada
        $disabledEquipments = $this->repository->findBy(['isReadingEnabled' => false]);
        $disabledKeys = array_map(fn($e) => $e->getKeyName(), $disabledEquipments);
        $this->assertContains('disabled_reading_' . $uniqueId, $disabledKeys);

        // Limpa
        $this->entityManager->remove($enabledEquipment);
        $this->entityManager->remove($disabledEquipment);
        $this->entityManager->remove($equipmentType);
        $this->entityManager->flush();
    }

    /**
     * Testa atribuição de valores dinâmicos (attributeValues) ao equipamento
     */
    public function testEquipmentAttributeValues(): void
    {
        // Cria tipo com definições de atributos
        $equipmentType = new EquipmentType();
        $equipmentType->setName('Attributes Test Type');
        $equipmentType->setServiceName('attr_service');
        $equipmentType->setAttributeDefinitions([
            ['name' => 'latitude', 'type' => 'float'],
            ['name' => 'longitude', 'type' => 'float'],
            ['name' => 'altitude', 'type' => 'float'],
        ]);

        // Cria equipamento com valores de atributos
        $equipment = new Equipment();
        $equipment->setName('Geo Equipment');
        $equipment->setKeyName('geo_equipment_' . uniqid());
        $equipment->setEquipmentType($equipmentType);
        $equipment->setAttributeValues([
            'latitude' => -23.5505,
            'longitude' => -46.6333,
            'altitude' => 760.0,
        ]);

        $this->entityManager->persist($equipmentType);
        $this->entityManager->persist($equipment);
        $this->entityManager->flush();

        // Recarrega e verifica
        $equipmentId = $equipment->getId();
        $this->entityManager->clear();

        $reloadedEquipment = $this->repository->find($equipmentId);
        $attributes = $reloadedEquipment->getAttributeValues();

        $this->assertArrayHasKey('latitude', $attributes);
        $this->assertArrayHasKey('longitude', $attributes);
        $this->assertArrayHasKey('altitude', $attributes);
        $this->assertEquals(-23.5505, $attributes['latitude']);
        $this->assertEquals(-46.6333, $attributes['longitude']);
        $this->assertEquals(760.0, $attributes['altitude']);

        // Limpa - recarrega o tipo antes de remover
        $equipmentType = $this->entityManager->find(EquipmentType::class, $equipmentType->getId());

        $this->entityManager->remove($reloadedEquipment);
        if ($equipmentType) $this->entityManager->remove($equipmentType);
        $this->entityManager->flush();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        // Limpa o entity manager
        $this->entityManager->close();
        $this->entityManager = null;
    }
}
