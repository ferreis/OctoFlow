<?php

namespace App\EventListener;

use App\Security\AuthenticationRateLimiter;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class AuthenticationRateLimitListener
{
    private const OBSERVED_ATTRIBUTE = '_octoflow_login_rate_limit_observed';
    private const IDENTITY_ATTRIBUTE = '_octoflow_login_rate_limit_identity';

    public function __construct(
        private readonly AuthenticationRateLimiter $rateLimiter,
    ) {
    }

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 20)]
    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if (!$this->isPasswordLoginRequest($request)) {
            return;
        }

        $identity = $this->extractIdentity($request);
        if ($identity === '') {
            return;
        }

        $retryAfter = $this->rateLimiter->retryAfter($request, $identity);
        if ($retryAfter > 0) {
            $response = new JsonResponse([
                'message' => 'Too many login attempts. Try again later.',
            ], JsonResponse::HTTP_TOO_MANY_REQUESTS);
            $response->headers->set('Retry-After', (string) $retryAfter);
            $response->headers->set('Cache-Control', 'no-store, private');
            $event->setResponse($response);

            return;
        }

        $request->attributes->set(self::OBSERVED_ATTRIBUTE, true);
        $request->attributes->set(self::IDENTITY_ATTRIBUTE, $identity);
    }

    #[AsEventListener(event: KernelEvents::RESPONSE, priority: -20)]
    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if ($request->attributes->getBoolean(self::OBSERVED_ATTRIBUTE) !== true) {
            return;
        }

        $identity = trim((string) $request->attributes->get(self::IDENTITY_ATTRIBUTE, ''));
        if ($identity === '') {
            return;
        }

        $statusCode = $event->getResponse()->getStatusCode();
        if ($statusCode >= 200 && $statusCode < 300) {
            $this->rateLimiter->clearIdentity($identity);

            return;
        }

        if (!in_array($statusCode, [JsonResponse::HTTP_UNAUTHORIZED, JsonResponse::HTTP_FORBIDDEN], true)) {
            return;
        }

        $this->rateLimiter->recordFailure($request, $identity);

        // Não exponha se o e-mail existe, se a conta está desativada ou se só aceita Google.
        $response = new JsonResponse([
            'message' => 'Invalid credentials.',
        ], JsonResponse::HTTP_UNAUTHORIZED);
        $response->headers->set('Cache-Control', 'no-store, private');
        $event->setResponse($response);
    }

    private function isPasswordLoginRequest(Request $request): bool
    {
        return $request->isMethod('POST') && $request->getPathInfo() === '/auth/login';
    }

    private function extractIdentity(Request $request): string
    {
        try {
            $payload = json_decode($request->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return '';
        }

        if (!is_array($payload)) {
            return '';
        }

        return mb_strtolower(trim((string) ($payload['email'] ?? '')));
    }
}
