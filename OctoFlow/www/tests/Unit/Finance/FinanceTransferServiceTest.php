<?php

namespace App\Tests\Unit\Finance;

use App\Entity\User;
use App\Finance\FinanceTransferService;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

final class FinanceTransferServiceTest extends TestCase
{
    public function testImportSnapshotRequiresStrongConfirmationBeforeDefaultReplacement(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->never())->method('beginTransaction');

        $service = new FinanceTransferService($connection);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('confirmationPhrase');

        $service->importSnapshot($this->buildUserWithId(15), [
            'snapshot' => [
                'version' => 1,
                'data' => [
                    'categories' => [],
                ],
            ],
        ]);
    }

    public function testImportSnapshotAllowsNonDestructiveImportWithoutConfirmation(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())->method('beginTransaction')->willReturn(true);
        $connection->expects($this->once())->method('commit')->willReturn(true);
        $connection->expects($this->never())->method('executeStatement');

        $service = new FinanceTransferService($connection);

        $result = $service->importSnapshot($this->buildUserWithId(15), [
            'replaceExisting' => false,
            'snapshot' => [
                'version' => 1,
                'data' => [
                    'categories' => [],
                ],
            ],
        ]);

        $this->assertFalse($result['replaceExisting']);
        $this->assertSame(0, $result['imported']['categories']);
    }

    private function buildUserWithId(int $id): User
    {
        $user = (new User())->setEmail('finance-owner@example.com');
        $property = new \ReflectionProperty(User::class, 'id');
        $property->setAccessible(true);
        $property->setValue($user, $id);

        return $user;
    }
}
