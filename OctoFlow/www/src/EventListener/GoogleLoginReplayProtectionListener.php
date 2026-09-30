<?php

namespace App\EventListener;

use App\Security\Google\GoogleLoginNonceManager;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

#[AsEventListener(event: KernelEvents::REQUEST, priority: 20)]
final class GoogleLoginReplayProtectionListener
{
    public function __construct(
        private readonly GoogleLoginNonceManager $nonceManager,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if (!$request->isMethod('POST') || $request->getPathInfo() !== '/auth/google') {
            return;
        }

        $credential = $this->extractCredential($request->getContent());
        $nonce = $credential !== null ? $this->extractNonceClaim($credential) : null;

        if ($nonce !== null && $this->nonceManager->consume($request, $nonce)) {
            return;
        }

        $response = new JsonResponse([
            'message' => 'Invalid or expired Google login state. Start the Google sign-in flow again.',
        ], JsonResponse::HTTP_UNAUTHORIZED);
        $response->headers->set('Cache-Control', 'no-store, private');
        $event->setResponse($response);
    }

    private function extractCredential(string $rawBody): ?string
    {
        try {
            $payload = json_decode($rawBody, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!is_array($payload)) {
            return null;
        }

        $credential = trim((string) ($payload['credential'] ?? ''));
        if ($credential === '' || strlen($credential) > 8192) {
            return null;
        }

        return $credential;
    }

    private function extractNonceClaim(string $credential): ?string
    {
        $tokenParts = explode('.', $credential);
        if (count($tokenParts) !== 3) {
            return null;
        }

        $payloadJson = $this->decodeBase64Url($tokenParts[1]);
        if ($payloadJson === null) {
            return null;
        }

        try {
            $payload = json_decode($payloadJson, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!is_array($payload)) {
            return null;
        }

        $nonce = $payload['nonce'] ?? null;
        if (!is_string($nonce)) {
            return null;
        }

        $normalizedNonce = trim($nonce);

        return $normalizedNonce === '' ? null : $normalizedNonce;
    }

    private function decodeBase64Url(string $value): ?string
    {
        if ($value === '' || preg_match('/^[A-Za-z0-9_-]+$/', $value) !== 1) {
            return null;
        }

        $remainder = strlen($value) % 4;
        if ($remainder > 0) {
            $value .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return is_string($decoded) ? $decoded : null;
    }
}
