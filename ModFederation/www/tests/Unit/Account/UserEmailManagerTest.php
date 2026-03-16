<?php

namespace App\Tests\Unit\Account;

use App\Account\Exception\UserEmailConflictException;
use App\Account\UserEmailManager;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class UserEmailManagerTest extends TestCase
{
    private EntityManagerInterface&MockObject $entityManager;
    private UserRepository&MockObject $userRepository;
    private UserEmailManager $manager;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->userRepository = $this->createMock(UserRepository::class);

        $this->manager = new UserEmailManager(
            $this->entityManager,
            $this->userRepository,
        );
    }

    public function testEnsureEmailAddsSecondaryEmailWithoutChangingDefault(): void
    {
        $user = (new User())
            ->setEmail('local@example.com')
            ->setPassword('hashed-password');

        $this->entityManager
            ->expects($this->exactly(2))
            ->method('persist');

        $this->userRepository
            ->expects($this->once())
            ->method('findOneByEmail')
            ->with('github@example.com')
            ->willReturn(null);

        $this->manager->ensureEmail($user, 'github@example.com', ['github'], true, false);

        $this->assertSame('local@example.com', $user->getEmail());
        $this->assertCount(2, $user->getEmailAddresses());

        $payload = $this->manager->buildPayload($user);
        $this->assertSame('local@example.com', $payload[0]['email']);
        $this->assertTrue($payload[0]['isPrimary']);
        $this->assertSame(['github'], $payload[1]['providers']);
    }

    public function testSetPrimaryEmailUpdatesDefaultEmail(): void
    {
        $user = (new User())
            ->setEmail('local@example.com')
            ->setPassword('hashed-password');

        $this->entityManager
            ->expects($this->exactly(2))
            ->method('persist');

        $this->userRepository
            ->expects($this->once())
            ->method('findOneByEmail')
            ->with('github@example.com')
            ->willReturn(null);

        $this->manager->ensureEmail($user, 'github@example.com', ['github'], true, false);
        $this->manager->setPrimaryEmail($user, 'github@example.com');

        $this->assertSame('github@example.com', $user->getEmail());

        $payload = $this->manager->buildPayload($user);
        $this->assertSame('github@example.com', $payload[0]['email']);
        $this->assertTrue($payload[0]['isPrimary']);
        $this->assertSame('local@example.com', $payload[1]['email']);
        $this->assertFalse($payload[1]['isPrimary']);
    }

    public function testEnsureEmailRejectsConflictWithAnotherUser(): void
    {
        $user = (new User())
            ->setEmail('local@example.com')
            ->setPassword('hashed-password');
        $this->setEntityId($user, 1);

        $anotherUser = (new User())
            ->setEmail('other@example.com')
            ->setPassword('hashed-password');
        $this->setEntityId($anotherUser, 2);

        $this->entityManager
            ->expects($this->once())
            ->method('persist');

        $this->userRepository
            ->expects($this->once())
            ->method('findOneByEmail')
            ->with('shared@example.com')
            ->willReturn($anotherUser);

        $this->expectException(UserEmailConflictException::class);
        $this->expectExceptionMessage('This email is already linked to another user.');

        $this->manager->ensureEmail($user, 'shared@example.com', ['github'], true, false);
    }

    private function setEntityId(User $user, int $id): void
    {
        $property = new \ReflectionProperty(User::class, 'id');
        $property->setAccessible(true);
        $property->setValue($user, $id);
    }
}
