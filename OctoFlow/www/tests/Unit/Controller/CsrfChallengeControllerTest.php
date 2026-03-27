<?php

namespace App\Tests\Unit\Controller;

use App\Controller\CsrfChallengeController;
use App\Security\CsrfTokenManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class CsrfChallengeControllerTest extends TestCase
{
    public function testAuthenticatedChallengeAllowsLocalTaskCreationAction(): void
    {
        $request = Request::create(
            '/csrf/challenge',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode([
                'method' => 'POST',
                'path' => '/tasks/local-issues',
                'actionId' => 'task.local.create',
            ], \JSON_THROW_ON_ERROR),
        );
        $request->setSession(new Session(new MockArraySessionStorage()));

        $controller = new CsrfChallengeController($this->createCsrfTokenManager());
        $response = $controller->__invoke($request);

        $this->assertSame(200, $response->getStatusCode());
        $payload = json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR);

        $this->assertSame('task.local.create', $payload['actionId']);
        $this->assertSame('POST', $payload['method']);
        $this->assertSame('/tasks/local-issues', $payload['path']);
        $this->assertArrayHasKey('csrfToken', $payload);
    }

    public function testAuthenticatedChallengeAllowsFinanceCategoryCreateAction(): void
    {
        $request = Request::create(
            '/csrf/challenge',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode([
                'method' => 'POST',
                'path' => '/finance/categories',
                'actionId' => 'finance.categories.create',
            ], \JSON_THROW_ON_ERROR),
        );
        $request->setSession(new Session(new MockArraySessionStorage()));

        $controller = new CsrfChallengeController($this->createCsrfTokenManager());
        $response = $controller->__invoke($request);

        $this->assertSame(200, $response->getStatusCode());
        $payload = json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR);

        $this->assertSame('finance.categories.create', $payload['actionId']);
        $this->assertSame('/finance/categories', $payload['path']);
        $this->assertArrayHasKey('csrfToken', $payload);
    }

    public function testAuthenticatedChallengeAllowsGooglePasswordSetupAction(): void
    {
        $request = Request::create(
            '/csrf/challenge',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode([
                'method' => 'POST',
                'path' => '/auth/google/password-setup/verify-code',
                'actionId' => 'auth.google.password-setup.verify-code',
            ], \JSON_THROW_ON_ERROR),
        );
        $request->setSession(new Session(new MockArraySessionStorage()));

        $controller = new CsrfChallengeController($this->createCsrfTokenManager());
        $response = $controller->__invoke($request);

        $this->assertSame(200, $response->getStatusCode());
        $payload = json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR);

        $this->assertSame('auth.google.password-setup.verify-code', $payload['actionId']);
        $this->assertSame('/auth/google/password-setup/verify-code', $payload['path']);
        $this->assertArrayHasKey('csrfToken', $payload);
    }

    public function testAuthenticatedChallengeAllowsProfilePasswordChangeAction(): void
    {
        $request = Request::create(
            '/csrf/challenge',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode([
                'method' => 'POST',
                'path' => '/auth/password-change/send-code',
                'actionId' => 'auth.password-change.send-code',
            ], \JSON_THROW_ON_ERROR),
        );
        $request->setSession(new Session(new MockArraySessionStorage()));

        $controller = new CsrfChallengeController($this->createCsrfTokenManager());
        $response = $controller->__invoke($request);

        $this->assertSame(200, $response->getStatusCode());
        $payload = json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR);

        $this->assertSame('auth.password-change.send-code', $payload['actionId']);
        $this->assertSame('/auth/password-change/send-code', $payload['path']);
        $this->assertArrayHasKey('csrfToken', $payload);
    }

    public function testAuthenticatedChallengeRejectsSafeMethods(): void
    {
        $request = Request::create(
            '/csrf/challenge',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode([
                'method' => 'GET',
                'path' => '/tasks/local-issues',
                'actionId' => 'task.local.create',
            ], \JSON_THROW_ON_ERROR),
        );
        $request->setSession(new Session(new MockArraySessionStorage()));

        $controller = new CsrfChallengeController($this->createCsrfTokenManager());
        $response = $controller->__invoke($request);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringContainsString('CSRF challenge is not available for this action.', (string) $response->getContent());
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
