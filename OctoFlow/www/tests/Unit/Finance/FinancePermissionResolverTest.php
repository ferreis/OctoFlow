<?php

namespace App\Tests\Unit\Finance;

use App\Entity\User;
use App\Finance\FinancePermissionResolver;
use PHPUnit\Framework\TestCase;

final class FinancePermissionResolverTest extends TestCase
{
    private FinancePermissionResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new FinancePermissionResolver();
    }

    public function testDefaultUserHasNoFinancePermissions(): void
    {
        $user = $this->buildUserWithRoles(['ROLE_USER']);

        $this->assertSame([], $this->resolver->resolve($user));
        $this->assertFalse($this->resolver->isGranted($user, FinancePermissionResolver::READ));
        $this->assertFalse($this->resolver->isGranted($user, FinancePermissionResolver::WRITE));
    }

    public function testReadRoleGrantsOnlyReadPermission(): void
    {
        $user = $this->buildUserWithRoles(['ROLE_FINANCE_READ']);

        $this->assertSame([FinancePermissionResolver::READ], $this->resolver->resolve($user));
        $this->assertTrue($this->resolver->isGranted($user, FinancePermissionResolver::READ));
        $this->assertFalse($this->resolver->isGranted($user, FinancePermissionResolver::WRITE));
    }

    public function testWriteRoleGrantsReadAndWritePermissions(): void
    {
        $user = $this->buildUserWithRoles(['ROLE_FINANCE_WRITE']);

        $this->assertSame(
            [FinancePermissionResolver::READ, FinancePermissionResolver::WRITE],
            $this->resolver->resolve($user),
        );
        $this->assertTrue($this->resolver->isGranted($user, FinancePermissionResolver::READ));
        $this->assertTrue($this->resolver->isGranted($user, FinancePermissionResolver::WRITE));
    }

    public function testDirectFinancePermissionAliasesAreAcceptedFromRoles(): void
    {
        $user = $this->buildUserWithRoles(['finance:read', 'finance-write']);

        $this->assertSame(
            [FinancePermissionResolver::READ, FinancePermissionResolver::WRITE],
            $this->resolver->resolve($user),
        );
    }

    public function testAdminRoleGrantsReadAndWritePermissions(): void
    {
        $user = $this->buildUserWithRoles(['ROLE_ADMIN']);

        $this->assertTrue($this->resolver->isGranted($user, FinancePermissionResolver::READ));
        $this->assertTrue($this->resolver->isGranted($user, FinancePermissionResolver::WRITE));
    }

    /**
     * @param list<string> $roles
     */
    private function buildUserWithRoles(array $roles): User
    {
        return (new User())
            ->setEmail('finance-user@example.com')
            ->setPassword('not-used')
            ->setRoles($roles);
    }
}
