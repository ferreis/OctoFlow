<?php

namespace App\Tests\Unit\Security;

use App\EventListener\CsrfProtectionListener;
use App\Security\CsrfTokenManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;

final class CsrfProtectionListenerTest extends TestCase
{
    public function testRejectsUiPatchWithoutCsrfToken(): void
    {
        $listener = new CsrfProtectionListener($this->createCsrfTokenManager());

        $request = Request::create('/ui/settings', 'PATCH');
        $kernel = $this->createMock(HttpKernelInterface::class);
        $event = new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);

        $listener($event);

        $response = $event->getResponse();

        $this->assertNotNull($response);
        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringContainsString('Invalid or missing CSRF token.', (string) $response->getContent());
    }

    private function createCsrfTokenManager(): CsrfTokenManager
    {
        return new CsrfTokenManager(
            'X-CSRF-Token',
            'X-CSRF-Action',
            600,
            'refresh_token'
        );
    }
}
