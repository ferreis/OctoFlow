<?php

namespace App\Finance;

use App\Entity\User;

final class FinancePermissionResolver
{
    public const READ = 'finance:read';
    public const WRITE = 'finance:write';

    /**
     * @var array<string, list<string>>
     */
    private const ROLE_PERMISSION_MAP = [
        'ROLE_ADMIN' => [self::READ, self::WRITE],
        'ROLE_FINANCE' => [self::READ, self::WRITE],
        'ROLE_FINANCE_ADMIN' => [self::READ, self::WRITE],
        'ROLE_FINANCE_ALL' => [self::READ, self::WRITE],
        'ROLE_FINANCE_READ' => [self::READ],
        'ROLE_FINANCE_READER' => [self::READ],
        'ROLE_FINANCE_WRITE' => [self::READ, self::WRITE],
        'ROLE_FINANCE_WRITER' => [self::READ, self::WRITE],
    ];

    /**
     * @return list<string>
     */
    public function resolve(User $user): array
    {
        $permissions = [];

        foreach ($user->getRoles() as $role) {
            foreach ($this->resolveRolePermissions((string) $role) as $permission) {
                $permissions[] = $permission;
            }
        }

        return array_values(array_unique($permissions));
    }

    public function isGranted(User $user, string $permission): bool
    {
        $normalizedPermission = $this->normalizePermission($permission);
        if ($normalizedPermission === null) {
            return false;
        }

        return in_array($normalizedPermission, $this->resolve($user), true);
    }

    /**
     * @return list<string>
     */
    private function resolveRolePermissions(string $role): array
    {
        $normalizedRole = strtoupper(trim($role));
        if ($normalizedRole === '') {
            return [];
        }

        if (isset(self::ROLE_PERMISSION_MAP[$normalizedRole])) {
            return self::ROLE_PERMISSION_MAP[$normalizedRole];
        }

        $alias = strtolower($normalizedRole);
        if (str_starts_with($alias, 'role_')) {
            $alias = substr($alias, 5);
        }

        $alias = str_replace('-', '_', $alias);

        return match ($alias) {
            '*',
            'admin',
            'finance',
            'finance:*',
            'finance_admin',
            'finance_all' => [self::READ, self::WRITE],
            'finance:read',
            'finance_read',
            'finance_reader' => [self::READ],
            'finance:write',
            'finance_write',
            'finance_writer' => [self::READ, self::WRITE],
            default => [],
        };
    }

    private function normalizePermission(string $permission): ?string
    {
        return match (strtolower(trim($permission))) {
            self::READ => self::READ,
            self::WRITE => self::WRITE,
            default => null,
        };
    }
}
