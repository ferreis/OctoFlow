<?php

namespace App\Tests\Unit\Security;

use App\Security\AuthenticationRateLimiter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\Request;

final class AuthenticationRateLimiterTest extends TestCase
{
    public function testBlocksIdentityAfterConfiguredNumberOfFailures(): void
    {
        $limiter = new AuthenticationRateLimiter(new ArrayAdapter(), 3, 20, 900);
        $request = $this->requestFromIp('203.0.113.10');

        $this->assertSame(0, $limiter->retryAfter($request, 'user@example.com'));

        $limiter->recordFailure($request, 'user@example.com');
        $limiter->recordFailure($request, 'USER@example.com ');
        $this->assertSame(0, $limiter->retryAfter($request, 'user@example.com'));

        $limiter->recordFailure($request, 'user@example.com');
        $this->assertGreaterThan(0, $limiter->retryAfter($request, 'user@example.com'));
    }

    public function testSuccessfulLoginCanClearIdentityCounterWithoutClearingIpFailures(): void
    {
        $limiter = new AuthenticationRateLimiter(new ArrayAdapter(), 2, 3, 900);
        $request = $this->requestFromIp('203.0.113.20');

        $limiter->recordFailure($request, 'first@example.com');
        $limiter->recordFailure($request, 'first@example.com');
        $this->assertGreaterThan(0, $limiter->retryAfter($request, 'first@example.com'));

        $limiter->clearIdentity('first@example.com');
        $this->assertSame(0, $limiter->retryAfter($request, 'first@example.com'));

        $limiter->recordFailure($request, 'second@example.com');
        $this->assertGreaterThan(0, $limiter->retryAfter($request, 'third@example.com'));
    }

    public function testIpLimitAggregatesFailuresAcrossDifferentIdentities(): void
    {
        $limiter = new AuthenticationRateLimiter(new ArrayAdapter(), 5, 3, 900);
        $request = $this->requestFromIp('198.51.100.40');

        $limiter->recordFailure($request, 'one@example.com');
        $limiter->recordFailure($request, 'two@example.com');
        $limiter->recordFailure($request, 'three@example.com');

        $this->assertGreaterThan(0, $limiter->retryAfter($request, 'four@example.com'));
    }

    private function requestFromIp(string $ipAddress): Request
    {
        return Request::create(
            '/auth/login',
            'POST',
            server: ['REMOTE_ADDR' => $ipAddress],
        );
    }
}
