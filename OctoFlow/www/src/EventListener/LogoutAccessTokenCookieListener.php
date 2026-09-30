<?php

namespace App\EventListener;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class LogoutAccessTokenCookieListener
{
    private const TOKEN_RESPONSE_PATHS = [
        '/auth/login',
        '/auth/register',
        '/auth/google',
        '/auth/refresh',
        '/auth/google/password-setup/set-password',
    ];

    public function __construct(
        #[Autowire('%env(string:AUTH_LOGOUT_ACCESS_COOKIE_NAME)%')]
        private readonly string $cookieName,
        #[Autowire('%env(bool:AUTH_REFRESH_COOKIE_SECURE)%')]
        private readonly bool $cookieSecure,
        #[Autowire('%env(string:AUTH_REFRESH_COOKIE_SAMESITE)%')]
        private readonly string $cookieSameSite,
        #[Autowire('%env(int:JWT_TOKEN_TTL)%')]
        private readonly int $accessTokenTtl,
    ) {
    }

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 20)]
    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if (!$request->isMethod('POST') || $request->getPathInfo() !== '/auth/logout') {
            return;
        }

        if (trim((string) $request->headers->get('Authorization', '')) !== '') {
            return;
        }

        $accessToken = trim((string) $request->cookies->get($this->normalizedCookieName(), ''));
        if ($accessToken === '' || strlen($accessToken) > 8192) {
            return;
        }

        // O CSRF é validado antes deste listener. O token é então injetado antes do firewall JWT,
        // permitindo que o controller revogue o access token mesmo que o frontend não o envie.
        $request->headers->set('Authorization', 'Bearer ' . $accessToken);
    }

    #[AsEventListener(event: KernelEvents::RESPONSE, priority: -30)]
    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $path = $request->getPathInfo();
        $response = $event->getResponse();

        if ($path === '/auth/logout') {
            $response->headers->setCookie($this->buildClearCookie($request));

            return;
        }

        if (!in_array($path, self::TOKEN_RESPONSE_PATHS, true) || !$response->isSuccessful()) {
            return;
        }

        $payload = json_decode((string) $response->getContent(), true);
        if (!is_array($payload)) {
            return;
        }

        $accessToken = trim((string) ($payload['token'] ?? ''));
        if ($accessToken === '' || strlen($accessToken) > 8192) {
            return;
        }

        $response->headers->setCookie($this->buildCookie($request, $accessToken));
    }

    private function buildCookie(Request $request, string $accessToken): Cookie
    {
        return Cookie::create($this->normalizedCookieName())
            ->withValue($accessToken)
            ->withPath($this->resolveLogoutCookiePath($request))
            ->withExpires(new \DateTimeImmutable(sprintf('+%d seconds', max(1, $this->accessTokenTtl))))
            ->withHttpOnly(true)
            ->withSecure($this->cookieSecure)
            ->withSameSite($this->resolveSameSite($this->cookieSameSite));
    }

    private function buildClearCookie(Request $request): Cookie
    {
        return Cookie::create($this->normalizedCookieName())
            ->withValue('')
            ->withPath($this->resolveLogoutCookiePath($request))
            ->withExpires(new \DateTimeImmutable('-1 day'))
            ->withHttpOnly(true)
            ->withSecure($this->cookieSecure)
            ->withSameSite($this->resolveSameSite($this->cookieSameSite));
    }

    private function resolveLogoutCookiePath(Request $request): string
    {
        $forwardedPrefix = trim((string) $request->headers->get('X-Forwarded-Prefix', ''), '/');
        if ($forwardedPrefix !== '') {
            return sprintf('/%s/auth/logout', $forwardedPrefix);
        }

        $basePath = trim((string) $request->getBasePath(), '/');
        if ($basePath !== '') {
            return sprintf('/%s/auth/logout', $basePath);
        }

        $pathInfo = '/' . ltrim((string) $request->getPathInfo(), '/');
        $authSegmentPosition = strpos($pathInfo, '/auth');
        if ($authSegmentPosition !== false) {
            $prefixPath = trim(substr($pathInfo, 0, $authSegmentPosition), '/');
            if ($prefixPath !== '') {
                return sprintf('/%s/auth/logout', $prefixPath);
            }
        }

        return '/auth/logout';
    }

    private function normalizedCookieName(): string
    {
        $normalizedName = trim($this->cookieName);

        return $normalizedName !== '' ? $normalizedName : 'logout_access_token';
    }

    private function resolveSameSite(string $sameSite): string
    {
        return match (strtolower(trim($sameSite))) {
            Cookie::SAMESITE_STRICT => Cookie::SAMESITE_STRICT,
            Cookie::SAMESITE_NONE => Cookie::SAMESITE_NONE,
            default => Cookie::SAMESITE_LAX,
        };
    }
}
