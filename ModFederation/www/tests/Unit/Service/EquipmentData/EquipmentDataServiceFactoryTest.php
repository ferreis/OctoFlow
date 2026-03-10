<?php

namespace App\Tests\Unit\Service\EquipmentData;

use App\Entity\Equipment;
use App\Entity\EquipmentType;
use App\Service\EquipmentData\EquipmentDataServiceFactory;
use App\Service\EquipmentData\EquipmentDataServiceInterface;
use PHPUnit\Framework\TestCase;

class EquipmentDataServiceFactoryTest extends TestCase
{
    /**
     * Verifica se a factory retorna o serviço correto com base no serviceName
     * do EquipmentType associado ao Equipment
     */
    public function testGetForEquipmentReturnsCorrectService(): void
    {
        $serviceName = 'test_service';

        $mockService = $this->createMock(EquipmentDataServiceInterface::class);
        $mockService->method('getServiceName')->willReturn($serviceName);

        $equipmentType = $this->createMock(EquipmentType::class);
        $equipmentType->method('getServiceName')->willReturn($serviceName);

        $equipment = $this->createMock(Equipment::class);
        $equipment->method('getEquipmentType')->willReturn($equipmentType);

        $factory = new EquipmentDataServiceFactory([$mockService]);

        $result = $factory->getForEquipment($equipment);

        $this->assertSame($mockService, $result);
    }

    /**
     * Verifica se uma exceção é lançada quando o Equipment não possui EquipmentType
     */
    public function testGetForEquipmentThrowsExceptionWhenEquipmentTypeIsNull(): void
    {
        $equipment = $this->createMock(Equipment::class);
        $equipment->method('getEquipmentType')->willReturn(null);

        $factory = new EquipmentDataServiceFactory([]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Equipamento sem tipo ou serviceName definido.');

        $factory->getForEquipment($equipment);
    }

    /**
     * Verifica se uma exceção é lançada quando o EquipmentType não possui serviceName definido
     */
    public function testGetForEquipmentThrowsExceptionWhenServiceNameIsNull(): void
    {
        $equipmentType = $this->createMock(EquipmentType::class);
        $equipmentType->method('getServiceName')->willReturn(null);

        $equipment = $this->createMock(Equipment::class);
        $equipment->method('getEquipmentType')->willReturn($equipmentType);

        $factory = new EquipmentDataServiceFactory([]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Equipamento sem tipo ou serviceName definido.');

        $factory->getForEquipment($equipment);
    }

    /**
     * Verifica se uma exceção é lançada quando nenhum serviço registrado
     * corresponde ao serviceName solicitado
     */
    public function testGetForEquipmentThrowsExceptionWhenNoServiceFound(): void
    {
        $serviceName = 'test_service';

        $mockService = $this->createMock(EquipmentDataServiceInterface::class);
        $mockService->method('getServiceName')->willReturn('different_service');

        $equipmentType = $this->createMock(EquipmentType::class);
        $equipmentType->method('getServiceName')->willReturn($serviceName);

        $equipment = $this->createMock(Equipment::class);
        $equipment->method('getEquipmentType')->willReturn($equipmentType);

        $factory = new EquipmentDataServiceFactory([$mockService]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Nenhum serviço registrado para o tipo "test_service".');

        $factory->getForEquipment($equipment);
    }

    /**
     * Verifica se quando há múltiplos serviços registrados, a factory
     * retorna corretamente o serviço que corresponde ao serviceName
     */
    public function testGetForEquipmentWithMultipleServicesReturnsCorrectOne(): void
    {
        $serviceName1 = 'service_one';
        $serviceName2 = 'service_two';

        $mockService1 = $this->createMock(EquipmentDataServiceInterface::class);
        $mockService1->method('getServiceName')->willReturn($serviceName1);

        $mockService2 = $this->createMock(EquipmentDataServiceInterface::class);
        $mockService2->method('getServiceName')->willReturn($serviceName2);

        $equipmentType = $this->createMock(EquipmentType::class);
        $equipmentType->method('getServiceName')->willReturn($serviceName2);

        $equipment = $this->createMock(Equipment::class);
        $equipment->method('getEquipmentType')->willReturn($equipmentType);

        $factory = new EquipmentDataServiceFactory([$mockService1, $mockService2]);

        $result = $factory->getForEquipment($equipment);

        $this->assertSame($mockService2, $result);
    }
}
