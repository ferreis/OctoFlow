<?php

namespace App\Tests\Integration\Repository;

use App\Entity\User;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Teste de integração para o UserRepository
 *
 * Este teste verifica as operações do repositório contra o banco de dados real,
 * garantindo que as consultas e persistências funcionam corretamente
 */
class UserRepositoryIntegrationTest extends KernelTestCase
{
    private UserRepository $repository;
    private $entityManager;

    protected function setUp(): void
    {
        // Inicia o kernel antes de cada teste
        self::bootKernel();

        // Recupera os serviços necessários do container
        $container = static::getContainer();
        $this->repository = $container->get(UserRepository::class);
        $this->entityManager = $container->get('doctrine')->getManager();
    }

    /**
     * Testa se o repositório pode ser recuperado do container de serviços
     */
    public function testRepositoryIsAvailableInContainer(): void
    {
        $this->assertInstanceOf(UserRepository::class, $this->repository);
    }

    /**
     * Testa a criação e persistência de um usuário no banco de dados
     */
    public function testCreateAndPersistUser(): void
    {
        // Cria um novo usuário
        $uniqueId = uniqid();
        $user = new User();
        $user->setName('Integration Test User');
        $user->setEmail('integration.test_' . $uniqueId . '@example.com');
        $user->setPassword('hashed_password_123');
        $user->setRoles(['ROLE_USER']);

        // Persiste o usuário usando o entity manager
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        // Verifica se o ID foi gerado
        $this->assertNotNull($user->getId());

        // Busca o usuário pelo ID
        $foundUser = $this->repository->find($user->getId());

        // Verifica se o usuário foi encontrado
        $this->assertNotNull($foundUser);
        $this->assertEquals('Integration Test User', $foundUser->getName());
        $this->assertEquals('integration.test_' . $uniqueId . '@example.com', $foundUser->getEmail());

        // Limpa o usuário de teste
        $this->entityManager->remove($user);
        $this->entityManager->flush();
    }

    /**
     * Testa a busca de usuário por email usando findOneBy
     */
    public function testFindUserByEmail(): void
    {
        // Cria um usuário de teste
        $email = 'findby.email_' . uniqid() . '@example.com';
        $user = new User();
        $user->setName('FindBy Test User');
        $user->setEmail($email);
        $user->setPassword('password123');

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        // Usa o repositório para buscar por email
        $foundUser = $this->repository->findOneBy(['email' => $email]);

        // Verifica o resultado
        $this->assertNotNull($foundUser);
        $this->assertInstanceOf(User::class, $foundUser);
        $this->assertEquals($email, $foundUser->getEmail());
        $this->assertEquals('FindBy Test User', $foundUser->getName());

        // Limpa
        $this->entityManager->remove($user);
        $this->entityManager->flush();
    }

    /**
     * Testa a contagem de usuários no banco de dados
     */
    public function testCountUsers(): void
    {
        // Conta usuários antes de criar novos
        $initialCount = $this->repository->count([]);

        // Cria alguns usuários de teste
        $uniqueId = uniqid();
        $users = [];
        for ($i = 1; $i <= 3; $i++) {
            $user = new User();
            $user->setName("Count Test User $i");
            $user->setEmail("count.test{$i}_{$uniqueId}@example.com");
            $user->setPassword('password');

            $this->entityManager->persist($user);
            $users[] = $user;
        }
        $this->entityManager->flush();

        // Verifica a contagem
        $newCount = $this->repository->count([]);
        $this->assertEquals($initialCount + 3, $newCount);

        // Limpa os usuários
        foreach ($users as $user) {
            $this->entityManager->remove($user);
        }
        $this->entityManager->flush();
    }

    /**
     * Testa o método upgradePassword do repositório
     * que é usado para atualizar senhas de forma segura
     */
    public function testUpgradePassword(): void
    {
        // Cria um usuário
        $user = new User();
        $user->setName('Password Upgrade Test');
        $user->setEmail('upgrade.password_' . uniqid() . '@example.com');
        $user->setPassword('old_password_hash');

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        // Atualiza a senha usando o método do repositório
        $newPasswordHash = 'new_improved_password_hash';
        $this->repository->upgradePassword($user, $newPasswordHash);

        // Recarrega o usuário do banco
        $this->entityManager->refresh($user);

        // Verifica se a senha foi atualizada
        $this->assertEquals($newPasswordHash, $user->getPassword());

        // Limpa
        $this->entityManager->remove($user);
        $this->entityManager->flush();
    }

    /**
     * Testa a busca de usuários ativos
     */
    public function testFindActiveUsers(): void
    {
        // Cria usuários ativos e inativos
        $uniqueId = uniqid();
        $activeUser = new User();
        $activeUser->setName('Active User');
        $activeUser->setEmail('active_' . $uniqueId . '@example.com');
        $activeUser->setPassword('password');
        $activeUser->setIsActive(true);

        $inactiveUser = new User();
        $inactiveUser->setName('Inactive User');
        $inactiveUser->setEmail('inactive_' . $uniqueId . '@example.com');
        $inactiveUser->setPassword('password');
        $inactiveUser->setIsActive(false);

        $this->entityManager->persist($activeUser);
        $this->entityManager->persist($inactiveUser);
        $this->entityManager->flush();

        // Busca apenas usuários ativos
        $activeUsers = $this->repository->findBy(['isActive' => true]);

        // Verifica que o usuário ativo está na lista
        $activeEmails = array_map(fn($u) => $u->getEmail(), $activeUsers);
        $this->assertContains('active_' . $uniqueId . '@example.com', $activeEmails);

        // Busca apenas usuários inativos
        $inactiveUsers = $this->repository->findBy(['isActive' => false]);
        $inactiveEmails = array_map(fn($u) => $u->getEmail(), $inactiveUsers);
        $this->assertContains('inactive_' . $uniqueId . '@example.com', $inactiveEmails);

        // Limpa
        $this->entityManager->remove($activeUser);
        $this->entityManager->remove($inactiveUser);
        $this->entityManager->flush();
    }

    /**
     * Testa operações de atualização de usuário
     */
    public function testUpdateUser(): void
    {
        // Cria um usuário
        $user = new User();
        $user->setName('Original Name');
        $user->setEmail('update.test_' . uniqid() . '@example.com');
        $user->setPassword('password');

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $userId = $user->getId();

        // Atualiza o nome
        $user->setName('Updated Name');
        $this->entityManager->flush();

        // Limpa o entity manager para forçar nova consulta
        $this->entityManager->clear();

        // Busca novamente o usuário
        $updatedUser = $this->repository->find($userId);

        // Verifica se a atualização foi persistida
        $this->assertEquals('Updated Name', $updatedUser->getName());

        // Limpa
        $this->entityManager->remove($updatedUser);
        $this->entityManager->flush();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        // Limpa o entity manager após cada teste
        $this->entityManager->close();
        $this->entityManager = null;
    }
}
