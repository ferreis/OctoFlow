<?php

namespace App\Github;

use App\Entity\User;

final class TemplateAccessService
{
    public function __construct(
        private readonly UserCapabilityResolver $userCapabilityResolver,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $templates
     *
     * @return list<array<string, mixed>>
     */
    public function filterVisibleTemplates(User $user, array $templates): array
    {
        $visibleTemplates = [];

        foreach ($templates as $template) {
            if (!$this->canViewTemplate($user, $template)) {
                continue;
            }

            $visibleTemplates[] = $this->sanitizeTemplate($template);
        }

        return array_values($visibleTemplates);
    }

    /**
     * @param array<string, mixed> $template
     */
    public function canViewTemplate(User $user, array $template): bool
    {
        return $this->isAllowed($user, $template, 'view');
    }

    /**
     * @param array<string, mixed> $template
     */
    public function canUseTemplate(User $user, array $template): bool
    {
        return $this->isAllowed($user, $template, 'use');
    }

    /**
     * @param array<string, mixed> $template
     *
     * @return array<string, mixed>
     */
    public function sanitizeTemplate(array $template): array
    {
        unset($template['access']);

        return $template;
    }

    /**
     * @param array<string, mixed> $template
     */
    private function isAllowed(User $user, array $template, string $action): bool
    {
        if (in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            return true;
        }

        $rule = $this->normalizeRule($this->resolveActionRule($template, $action));
        if ($rule === []) {
            return true;
        }

        $userRoles = array_values(array_unique(array_map(
            static fn (string $role): string => strtoupper(trim($role)),
            $user->getRoles(),
        )));
        $userCapabilities = $this->userCapabilityResolver->resolve($user);

        if ($rule['rolesAll'] !== [] && !$this->containsAll($userRoles, $rule['rolesAll'])) {
            return false;
        }

        if ($rule['rolesAny'] !== [] && !$this->containsAny($userRoles, $rule['rolesAny'])) {
            return false;
        }

        if ($rule['capabilitiesAll'] !== [] && !$this->containsAllCapabilities($userCapabilities, $rule['capabilitiesAll'])) {
            return false;
        }

        if ($rule['capabilitiesAny'] !== [] && !$this->containsAnyCapabilities($userCapabilities, $rule['capabilitiesAny'])) {
            return false;
        }

        return true;
    }

    /**
     * @param array<string, mixed> $template
     *
     * @return array<string, mixed>
     */
    private function resolveActionRule(array $template, string $action): array
    {
        $access = $template['access'] ?? [];
        if (!is_array($access) || $access === []) {
            return [];
        }

        $hasNestedRules = isset($access['view']) || isset($access['use']);
        if (!$hasNestedRules) {
            return $access;
        }

        $actionRule = $access[$action] ?? null;
        if (is_array($actionRule)) {
            return $actionRule;
        }

        $fallbackRule = $access['use'] ?? $access['view'] ?? [];

        return is_array($fallbackRule) ? $fallbackRule : [];
    }

    /**
     * @param array<string, mixed> $rule
     *
     * @return array{
     *     rolesAny: list<string>,
     *     rolesAll: list<string>,
     *     capabilitiesAny: list<string>,
     *     capabilitiesAll: list<string>
     * }
     */
    private function normalizeRule(array $rule): array
    {
        return [
            'rolesAny' => $this->normalizeStringList($rule['rolesAny'] ?? []),
            'rolesAll' => $this->normalizeStringList($rule['rolesAll'] ?? []),
            'capabilitiesAny' => $this->normalizeStringList($rule['capabilitiesAny'] ?? []),
            'capabilitiesAll' => $this->normalizeStringList($rule['capabilitiesAll'] ?? []),
        ];
    }

    /**
     * @param mixed $values
     *
     * @return list<string>
     */
    private function normalizeStringList(mixed $values): array
    {
        if (!is_array($values)) {
            return [];
        }

        $normalizedValues = [];

        foreach ($values as $value) {
            $normalizedValue = trim((string) $value);
            if ($normalizedValue !== '') {
                $normalizedValues[] = $normalizedValue;
            }
        }

        return array_values(array_unique($normalizedValues));
    }

    /**
     * @param list<string> $availableValues
     * @param list<string> $requiredValues
     */
    private function containsAny(array $availableValues, array $requiredValues): bool
    {
        foreach ($requiredValues as $requiredValue) {
            if (in_array($requiredValue, $availableValues, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $availableValues
     * @param list<string> $requiredValues
     */
    private function containsAll(array $availableValues, array $requiredValues): bool
    {
        foreach ($requiredValues as $requiredValue) {
            if (!in_array($requiredValue, $availableValues, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<string> $availableCapabilities
     * @param list<string> $requiredCapabilities
     */
    private function containsAnyCapabilities(array $availableCapabilities, array $requiredCapabilities): bool
    {
        foreach ($requiredCapabilities as $requiredCapability) {
            if ($this->hasCapability($availableCapabilities, $requiredCapability)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $availableCapabilities
     * @param list<string> $requiredCapabilities
     */
    private function containsAllCapabilities(array $availableCapabilities, array $requiredCapabilities): bool
    {
        foreach ($requiredCapabilities as $requiredCapability) {
            if (!$this->hasCapability($availableCapabilities, $requiredCapability)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<string> $availableCapabilities
     */
    private function hasCapability(array $availableCapabilities, string $requiredCapability): bool
    {
        $normalizedRequiredCapability = trim($requiredCapability);
        if ($normalizedRequiredCapability === '') {
            return true;
        }

        foreach ($availableCapabilities as $availableCapability) {
            $normalizedAvailableCapability = trim($availableCapability);
            if ($normalizedAvailableCapability === '') {
                continue;
            }

            if ($normalizedAvailableCapability === 'template.all' || $normalizedAvailableCapability === $normalizedRequiredCapability) {
                return true;
            }

            if (str_ends_with($normalizedAvailableCapability, '.all')) {
                $capabilityPrefix = substr($normalizedAvailableCapability, 0, -4);
                if ($capabilityPrefix !== '' && str_starts_with($normalizedRequiredCapability, $capabilityPrefix . '.')) {
                    return true;
                }
            }
        }

        return false;
    }
}
