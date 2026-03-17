<?php

namespace App\EventListener;

use App\Security\AccessTokenBlacklistManager;
use App\Security\RefreshTokenManager;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

#[AsEventListener(event: KernelEvents::REQUEST, priority: 20)]
final class JwtHttpOnlyGuardListener
{
    public function __construct(
        private readonly AccessTokenBlacklistManager $blacklistManager,
        private readonly RefreshTokenManager $refreshTokenManager,
        #[Autowire('%env(string:AUTH_REFRESH_TOKEN_COOKIE_NAME)%')]
        private readonly string $refreshCookieName,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $path = $request->getPathInfo();

        if (!$this->isProtectedPath($path)) {
            return;
        }

        $jwt = $this->extractBearerToken($request->headers->get('Authorization'));
        if ($jwt === null) {
            return;
        }

        $jwtPayload = $this->decodeJwtPayload($jwt);

        if ($this->blacklistManager->isBlacklisted($jwt)) {
            $event->setResponse(new JsonResponse([
                'message' => 'Access token is blacklisted.',
            ], JsonResponse::HTTP_UNAUTHORIZED));

            return;
        }

        $refreshToken = (string) $request->cookies->get($this->refreshCookieName, '');
        $jwtUserIdentifier = $this->extractJwtUserIdentifier($jwtPayload);

        if ($this->refreshTokenManager->isValidForUser($refreshToken, $jwtUserIdentifier)) {
            return;
        }

        $this->blacklistManager->blacklist(
            $jwt,
            $this->extractJwtExpiration($jwtPayload),
            $this->resolveBlacklistReason($refreshToken, $jwtUserIdentifier)
        );

        $event->setResponse(new JsonResponse([
            'message' => 'JWT must be sent together with the HttpOnly token cookie. Token was blacklisted.',
        ], JsonResponse::HTTP_UNAUTHORIZED));
    }

    private function isProtectedPath(string $path): bool
    {
        if ($this->isPublicPath($path)) {
            return false;
        }

        return preg_match('#^/(auth|api|tasks|csrf)(?:/|$)#', $path) === 1;
    }

    private function isPublicPath(string $path): bool
    {
        return preg_match('#^/(auth/login|auth/register|auth/google|auth/refresh|auth/logout|auth/config|auth/csrf/challenge|docs|contexts)(?:/|$)#', $path) === 1;
    }

    private function extractBearerToken(?string $authorizationHeader): ?string
    {
        if (!is_string($authorizationHeader) || trim($authorizationHeader) === '') {
            return null;
        }

        if (!preg_match('/^Bearer\s+(.+)$/i', $authorizationHeader, $matches)) {
            return null;
        }

        $token = trim($matches[1]);

        return $token === '' ? null : $token;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeJwtPayload(string $jwt): ?array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            return null;
        }

        $payload = $this->decodeBase64Url($parts[1]);
        if ($payload === null) {
            return null;
        }

        $decodedPayload = json_decode($payload, true);
        if (!is_array($decodedPayload)) {
            return null;
        }

        /** @var array<string, mixed> $decodedPayload */
        return $decodedPayload;
    }

    /**
     * @param array<string, mixed>|null $payload
     */
    private function extractJwtExpiration(?array $payload): ?\DateTimeImmutable
    {
        if ($payload === null || !isset($payload['exp'])) {
            return null;
        }

        $exp = (int) $payload['exp'];
        if ($exp <= 0) {
            return null;
        }

        return (new \DateTimeImmutable())->setTimestamp($exp);
    }

    /**
     * @param array<string, mixed>|null $payload
     */
    private function extractJwtUserIdentifier(?array $payload): ?string
    {
        if ($payload === null) {
            return null;
        }

        $candidate = $payload['username'] ?? $payload['sub'] ?? null;
        if (!is_string($candidate)) {
            return null;
        }

        $normalized = trim($candidate);

        return $normalized === '' ? null : $normalized;
    }

    private function resolveBlacklistReason(string $refreshToken, ?string $jwtUserIdentifier): string
    {
        if ($refreshToken === '') {
            return 'missing_http_only_cookie';
        }

        if ($jwtUserIdentifier === null) {
            return 'invalid_jwt_payload';
        }

        return 'invalid_or_mismatched_http_only_cookie';
    }

    private function decodeBase64Url(string $value): ?string
    {
        $remainder = strlen($value) % 4;
        if ($remainder > 0) {
            $value .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return is_string($decoded) ? $decoded : null;
    }
}
