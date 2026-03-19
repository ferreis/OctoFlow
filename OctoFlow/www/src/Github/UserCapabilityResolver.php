<?php

namespace App\Github;

use App\Entity\User;

final class UserCapabilityResolver
{
    /**
     * @var array<string, list<string>>
     */
    private const ROLE_CAPABILITY_MAP = [
        'ROLE_ADMIN' => [
            'template.all',
        ],
        'ROLE_SUPPORT' => [
            'template.create.rule-question.use',
            'template.create.service-request.use',
            'template.create.support-request.use',
            'template.update.blocker-update.use',
            'template.update.status-update.use',
        ],
        'ROLE_QA' => [
            'template.create.bug-report.use',
            'template.create.rule-question.use',
            'template.update.status-update.use',
        ],
        'ROLE_MAINTAINER' => [
            'template.create.maintenance-task.use',
            'template.update.handoff-update.use',
            'template.update.resolution-update.use',
        ],
    ];

    /**
     * @return list<string>
     */
    public function resolve(User $user): array
    {
        $capabilities = [];

        foreach ($user->getRoles() as $role) {
            $normalizedRole = strtoupper(trim((string) $role));
            if ($normalizedRole === '') {
                continue;
            }

            foreach (self::ROLE_CAPABILITY_MAP[$normalizedRole] ?? [] as $capability) {
                $normalizedCapability = trim((string) $capability);
                if ($normalizedCapability !== '') {
                    $capabilities[] = $normalizedCapability;
                }
            }
        }

        return array_values(array_unique($capabilities));
    }
}
