<?php

namespace App\Tests\Unit\Security\Google;

use App\EventListener\GoogleLoginReplayProtectionListener;
use App\Security\Google\GoogleLoginNonceManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class GoogleLoginReplayProtectionListenerTest extends TestCase
{
    public function testConsumesIssuedNonceAndRejectsReplay(): void
    {
        $nonceManager = new GoogleLoginNonceManager(300);
        $listener = new GoogleLoginReplayProtectionListener($nonceManager);
        $session = new Session(new MockArraySessionStorage());

        $nonceRequest = Request::create('/auth/google/nonce', 'GET');
        $nonceRequest->setSession($session);
        $nonce = $nonceManager->issue($nonceRequest)['nonce'];
        $credential = $this->buildUnsignedCredentialWithNonce($nonce);

        $firstEvent = $this->eventForCredential($session, $credential);
        $listener($firstEvent);
        $this->assertFalse($firstEvent->hasResponse());

        $replayEvent = $this->eventForCredential($session, $credential);
        $listener($replayEvent);
        $this->assertTrue($replayEvent->hasResponse());
        $this->assertSame(401, $replayEvent->getResponse()?->getStatusCode());
    }

    public function testRejectsCredentialWithoutNonceClaim(): void
    {
        $nonceManager = new GoogleLoginNonceManager(300);
        $listener = new GoogleLoginReplayProtectionListener($nonceManager);
        $session = new Session(new MockArraySessionStorage());
        $credential = $this->buildUnsignedCredentialWithNonce(null);

        $event = $this->eventForCredential($session, $credential);
        $listener($event);

        $this->assertTrue($event->hasResponse());
        $this->assertSame(401, $event->getResponse()?->getStatusCode());
    }

    private function eventForCredential(Session $session, string $credential): RequestEvent
    {
        $request = Request::create(
            '/auth/google',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['credential' => $credential], \JSON_THROW_ON_ERROR),
        );
        $request->setSession($session);

        return new RequestEvent(
            $this->createMock(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
        );
    }

    private function buildUnsignedCredentialWithNonce(?string $nonce): string
    {
        $header = $this->base64UrlEncode(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], \JSON_THROW_ON_ERROR));
        $payloadData = ['sub' => 'test-subject'];
        if ($nonce !== null) {
            $payloadData['nonce'] = $nonce;
        }
        $payload = $this->base64UrlEncode(json_encode($payloadData, \JSON_THROW_ON_ERROR));

        return sprintf('%s.%s.signature', $header, $payload);
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
