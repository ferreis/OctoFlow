<?php

namespace App\Tests\Unit\Entity;

use App\Entity\User;
use PHPUnit\Framework\TestCase;

class UserTest extends TestCase
{
    private User $user;

    protected function setUp(): void
    {
        $this->user = new User();
    }

    /**
     * Testa os métodos getters e setters básicos da entidade User
     * (name, email, password)
     */
    public function testGettersAndSetters(): void
    {
        $this->user->setName('John Doe');
        $this->assertEquals('John Doe', $this->user->getName());

        $this->user->setEmail('john@example.com');
        $this->assertEquals('john@example.com', $this->user->getEmail());

        $this->user->setPassword('hashedpassword');
        $this->assertEquals('hashedpassword', $this->user->getPassword());
    }

    /**
     * Verifica se o getUserIdentifier retorna o email do usuário
     */
    public function testGetUserIdentifierReturnsEmail(): void
    {
        $this->user->setEmail('test@example.com');
        $this->assertEquals('test@example.com', $this->user->getUserIdentifier());
    }

    /**
     * Verifica se o método getRoles sempre inclui a role ROLE_USER por padrão
     */
    public function testGetRolesAlwaysIncludesRoleUser(): void
    {
        $roles = $this->user->getRoles();

        $this->assertContains('ROLE_USER', $roles);
    }

    /**
     * Verifica se ao definir roles customizadas, a ROLE_USER ainda é incluída
     */
    public function testSetRoles(): void
    {
        $this->user->setRoles(['ROLE_ADMIN']);
        $roles = $this->user->getRoles();

        $this->assertContains('ROLE_USER', $roles);
        $this->assertContains('ROLE_ADMIN', $roles);
    }

    /**
     * Verifica se o método getRoles remove roles duplicadas usando array_unique
     */
    public function testGetRolesReturnsUniqueRoles(): void
    {
        $this->user->setRoles(['ROLE_USER', 'ROLE_ADMIN', 'ROLE_USER']);
        $roles = $this->user->getRoles();

        $this->assertCount(2, $roles);
        $this->assertContains('ROLE_USER', $roles);
        $this->assertContains('ROLE_ADMIN', $roles);
    }

    /**
     * Verifica se a flag isActive tem valor padrão true
     */
    public function testIsActiveDefaultsToTrue(): void
    {
        $this->assertTrue($this->user->getIsActive());
    }

    /**
     * Testa a alteração do valor da flag isActive
     */
    public function testSetIsActive(): void
    {
        $this->user->setIsActive(false);
        $this->assertFalse($this->user->getIsActive());

        $this->user->setIsActive(true);
        $this->assertTrue($this->user->getIsActive());
    }

    /**
     * Verifica se o método isActive() (sem prefixo get) funciona corretamente
     */
    public function testIsActiveMethod(): void
    {
        $this->user->setIsActive(true);
        $this->assertTrue($this->user->isActive());

        $this->user->setIsActive(false);
        $this->assertFalse($this->user->isActive());
    }

    /**
     * Testa se os métodos retornam a instância da classe (fluent interface)
     * permitindo encadeamento de chamadas
     */
    public function testFluentInterface(): void
    {
        $result = $this->user
            ->setName('Jane Doe')
            ->setEmail('jane@example.com')
            ->setPassword('password')
            ->setIsActive(true)
            ->setRoles(['ROLE_ADMIN']);

        $this->assertInstanceOf(User::class, $result);
        $this->assertEquals('Jane Doe', $this->user->getName());
        $this->assertEquals('jane@example.com', $this->user->getEmail());
    }

    /**
     * Verifica se mesmo com array de roles vazio, a ROLE_USER é sempre retornada
     */
    public function testEmptyRolesArrayStillReturnsRoleUser(): void
    {
        $this->user->setRoles([]);
        $roles = $this->user->getRoles();

        $this->assertCount(1, $roles);
        $this->assertContains('ROLE_USER', $roles);
    }
}
