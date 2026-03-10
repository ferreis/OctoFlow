<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Security\RefreshTokenManager;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[AsController]
#[Route('/auth')]
class AuthController
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly JWTTokenManagerInterface $jwtTokenManager,
        private readonly RefreshTokenManager $refreshTokenManager,
        #[Autowire('%env(string:AUTH_REFRESH_TOKEN_COOKIE_NAME)%')]
        private readonly string $refreshCookieName,
        #[Autowire('%env(bool:AUTH_REFRESH_COOKIE_SECURE)%')]
        private readonly bool $refreshCookieSecure,
        #[Autowire('%env(string:AUTH_REFRESH_COOKIE_SAMESITE)%')]
        private readonly string $refreshCookieSameSite,
        #[Autowire('%env(int:JWT_TOKEN_TTL)%')]
        private readonly int $accessTokenTtl,
    ) {
    }

    #[Route('/login', name: 'auth_login', methods: ['POST'])]
    public function login(Request $request): JsonResponse
    {
        $payload = $this->decodeJson($request);
        if ($payload === null) {
            return new JsonResponse(['message' => 'Invalid JSON payload.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $email = mb_strtolower(trim((string) ($payload['email'] ?? '')));
        $password = (string) ($payload['password'] ?? '');

        if ($email === '' || $password === '') {
            return new JsonResponse(['message' => 'Email and password are required.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $user = $this->userRepository->findOneByEmail($email);
        if ($user === null || !$this->passwordHasher->isPasswordValid($user, $password)) {
            return new JsonResponse(['message' => 'Invalid credentials.'], JsonResponse::HTTP_UNAUTHORIZED);
        }

        if (!$user->isActive()) {
            return new JsonResponse(['message' => 'User account is disabled.'], JsonResponse::HTTP_FORBIDDEN);
        }

        $accessToken = $this->jwtTokenManager->create($user);
        $issuedRefreshToken = $this->refreshTokenManager->issue($user, $request);

        $response = new JsonResponse([
            'token' => $accessToken,
            'token_type' => 'Bearer',
            'expires_in' => $this->accessTokenTtl,
            'user' => $this->buildUserPayload($user),
        ]);

        $response->headers->setCookie($this->buildRefreshCookie($issuedRefreshToken->plainToken, $issuedRefreshToken->expiresAt));

        return $response;
    }

    #[Route('/refresh', name: 'auth_refresh', methods: ['POST'])]
    public function refresh(Request $request): JsonResponse
    {
        $refreshToken = $request->cookies->get($this->refreshCookieName);
        if ($refreshToken === null || $refreshToken === '') {
            return new JsonResponse(['message' => 'Refresh token cookie is missing.'], JsonResponse::HTTP_UNAUTHORIZED);
        }

        $issuedRefreshToken = $this->refreshTokenManager->rotate($refreshToken, $request);
        if ($issuedRefreshToken === null) {
            $response = new JsonResponse(['message' => 'Invalid or expired refresh token.'], JsonResponse::HTTP_UNAUTHORIZED);
            $response->headers->setCookie($this->buildClearRefreshCookie());

            return $response;
        }

        $accessToken = $this->jwtTokenManager->create($issuedRefreshToken->user);

        $response = new JsonResponse([
            'token' => $accessToken,
            'token_type' => 'Bearer',
            'expires_in' => $this->accessTokenTtl,
            'user' => $this->buildUserPayload($issuedRefreshToken->user),
        ]);
        $response->headers->setCookie($this->buildRefreshCookie($issuedRefreshToken->plainToken, $issuedRefreshToken->expiresAt));

        return $response;
    }

    #[Route('/logout', name: 'auth_logout', methods: ['POST'])]
    public function logout(Request $request): JsonResponse
    {
        $refreshToken = $request->cookies->get($this->refreshCookieName);
        $this->refreshTokenManager->revokeByPlainToken($refreshToken);

        $response = new JsonResponse(['message' => 'Logged out successfully.']);
        $response->headers->setCookie($this->buildClearRefreshCookie());

        return $response;
    }

    #[Route('/me', name: 'auth_me', methods: ['GET'])]
    public function me(#[CurrentUser] ?User $user): JsonResponse
    {
        if ($user === null) {
            return new JsonResponse(['message' => 'Unauthorized.'], JsonResponse::HTTP_UNAUTHORIZED);
        }

        return new JsonResponse(['user' => $this->buildUserPayload($user)]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeJson(Request $request): ?array
    {
        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($request->getContent(), true, flags: \JSON_THROW_ON_ERROR);

            return $decoded;
        } catch (\JsonException) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildUserPayload(User $user): array
    {
        return [
            'id' => $user->getId(),
            'email' => $user->getEmail(),
            'roles' => $user->getRoles(),
            'isActive' => $user->isActive(),
        ];
    }

    private function buildRefreshCookie(string $plainToken, \DateTimeImmutable $expiresAt): Cookie
    {
        return Cookie::create($this->refreshCookieName)
            ->withValue($plainToken)
            ->withPath('/')
            ->withExpires($expiresAt)
            ->withHttpOnly(true)
            ->withSecure($this->refreshCookieSecure)
            ->withSameSite($this->resolveSameSite($this->refreshCookieSameSite));
    }

    private function buildClearRefreshCookie(): Cookie
    {
        return Cookie::create($this->refreshCookieName)
            ->withValue('')
            ->withPath('/')
            ->withExpires(new \DateTimeImmutable('-1 day'))
            ->withHttpOnly(true)
            ->withSecure($this->refreshCookieSecure)
            ->withSameSite($this->resolveSameSite($this->refreshCookieSameSite));
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
