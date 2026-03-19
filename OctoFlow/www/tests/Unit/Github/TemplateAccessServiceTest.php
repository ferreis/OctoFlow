<?php

namespace App\Tests\Unit\Github;

use App\Entity\User;
use App\Github\TemplateAccessService;
use App\Github\UserCapabilityResolver;
use PHPUnit\Framework\TestCase;

final class TemplateAccessServiceTest extends TestCase
{
    private TemplateAccessService $service;

    protected function setUp(): void
    {
        $this->service = new TemplateAccessService(new UserCapabilityResolver());
    }

    public function testFilterVisibleTemplatesRemovesTemplatesOutsideUserAccess(): void
    {
        $user = (new User())
            ->setEmail('support@example.com')
            ->setPassword('not-used')
            ->setRoles(['ROLE_SUPPORT']);

        $visibleTemplates = $this->service->filterVisibleTemplates($user, [
            [
                'key' => 'public-template',
                'name' => 'Publico',
            ],
            [
                'key' => 'support-template',
                'name' => 'Suporte',
                'access' => [
                    'capabilitiesAny' => ['template.create.support-request.use'],
                ],
            ],
            [
                'key' => 'admin-template',
                'name' => 'Admin',
                'access' => [
                    'rolesAny' => ['ROLE_ADMIN'],
                ],
            ],
        ]);

        $this->assertCount(2, $visibleTemplates);
        $this->assertSame('public-template', $visibleTemplates[0]['key']);
        $this->assertSame('support-template', $visibleTemplates[1]['key']);
        $this->assertArrayNotHasKey('access', $visibleTemplates[1]);
    }

    public function testViewAndUseCanHaveDifferentAccessRules(): void
    {
        $user = (new User())
            ->setEmail('support@example.com')
            ->setPassword('not-used')
            ->setRoles(['ROLE_SUPPORT']);

        $template = [
            'key' => 'restricted-template',
            'label' => 'Restrito',
            'access' => [
                'view' => [
                    'rolesAny' => ['ROLE_SUPPORT'],
                ],
                'use' => [
                    'rolesAny' => ['ROLE_ADMIN'],
                ],
            ],
        ];

        $this->assertTrue($this->service->canViewTemplate($user, $template));
        $this->assertFalse($this->service->canUseTemplate($user, $template));
    }
}
