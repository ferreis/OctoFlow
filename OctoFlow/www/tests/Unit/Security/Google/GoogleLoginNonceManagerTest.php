<?php

namespace App\Tests\Unit\Security\Google;

use App\Security\Google\GoogleLoginNonceManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class GoogleLoginNonceManagerTest extends TestCase
{
    public function testIssuedNonceIsSingleUse(): void
    {
        $manager = new GoogleLoginNonceManager(300);
        $request = $this->requestWithSession();

        $issued = $manager->issue($request);

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{32,128}$/', $issued['nonce']);
        $this->assertSame(300, $issued['expiresIn']);
        $this->assertTrue($manager->consume($request, $issued['nonce']));
        $this->assertFalse($manager->consume($request, $issued['nonce']));
    }

    public function testKeepsMultipleRecentNoncesWithoutInvalidatingPreviousFlow(): void
    {
        $manager = new GoogleLoginNonceManager(300);
        $request = $this->requestWithSession();

        $first = $manager->issue($request);
        $second = $manager->issue($request);

        $this->assertNotSame($first['nonce'], $second['nonce']);
        $this->assertTrue($manager->consume($request, $first['nonce']));
        $this->assertTrue($manager->consume($request, $second['nonce']));
    }

    public function testRejectsMalformedNonce(): void
    {
        $manager = new GoogleLoginNonceManager(300);
        $request = $this->requestWithSession();

        $this->assertFalse($manager->consume($request, '../not-a-valid-nonce'));
    }

    private function requestWithSession(): Request
    {
        $request = Request::create('/auth/google/nonce', 'GET');
        $request->setSession(new Session(new MockArraySessionStorage()));

        return $request;
    }
}
