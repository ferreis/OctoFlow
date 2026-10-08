<?php

namespace App\Controller;

use App\Account\Exception\UserEmailConflictException;
use App\Account\GooglePasswordSetupMailer;
use App\Account\GooglePasswordSetupManager;
use App\Account\UserEmailManager;
use App\Account\UserPayloadBuilder;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Security\AccessTokenManagerInterface;
use App\Security\CsrfTokenManager;
use App\Security\RefreshTokenManager;
use App\Security\Google\Exception\GoogleAccountLinkException;
use App\Security\Google\Exception\GoogleOAuthConfigurationException;
use App\Security\Google\Exception\GoogleTokenVerificationException;
use App\Security\Google\GoogleIdentity;
use App\Security\Google\GoogleIdentityVerifier;
use Doctrine\ORM\EntityManagerInterface;
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
    /**
     * @var array<string, array{method: string, path: string}>
     */
    private const PUBLIC_CSRF_ACTIONS = [
        'auth.login' => ['method' => 'POST', 'path' => '/auth/login'],
        'auth.register' => ['method' => 'POST', 'path' => '/auth/register'],
        'auth.google' => ['method' => 'POST', 'path' => '/auth/google'],
        'auth.refresh' => ['method' => 'POST', 'path' => '/auth/refresh'],
        'auth.logout' => ['method' => 'POST', 'path' => '/auth/logout'],
    ];

    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly AccessTokenManagerInterface $accessTokenManager,
        private readonly RefreshTokenManager $refreshTokenManager,
        private readonly CsrfTokenManager $csrfTokenManager,
        private readonly GoogleIdentityVerifier $googleIdentityVerifier,
        private readonly UserEmailManager $userEmailManager,
        private readonly GooglePasswordSetupManager $googlePasswordSetupManager,
        private readonly GooglePasswordSetupMailer $googlePasswordSetupMailer,
        private readonly UserPayloadBuilder $userPayloadBuilder,
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

    #[Route('/csrf/challenge', name: 'auth_csrf_challenge', methods: ['POST'])]
    public function csrfChallenge(Request $request): JsonResponse
    {
        $payload = $this->decodeJson($request);
        if ($payload === null) {
            return new JsonResponse(['message' => 'Invalid JSON payload.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $actionId = trim((string) ($payload['actionId'] ?? ''));
        $definition = self::PUBLIC_CSRF_ACTIONS[$actionId] ?? null;
        if ($definition === null) {
            return new JsonResponse(['message' => 'CSRF challenge is not available for this action.'], JsonResponse::HTTP_FORBIDDEN);
        }

        $requestedMethod = strtoupper(trim((string) ($payload['method'] ?? '')));
        $requestedPath = '/' . ltrim((string) ($payload['path'] ?? ''), '/');

        if ($requestedMethod !== $definition['method'] || $requestedPath !== $definition['path']) {
            return new JsonResponse(['message' => 'CSRF challenge payload does not match the requested action.'], JsonResponse::HTTP_FORBIDDEN);
        }

        try {
            $challenge = $this->csrfTokenManager->issueChallenge($request, $requestedMethod, $requestedPath, $actionId);
        } catch (\InvalidArgumentException $exception) {
            return new JsonResponse(['message' => $exception->getMessage()], JsonResponse::HTTP_BAD_REQUEST);
        }

        return new JsonResponse($challenge);
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
        if ($user === null) {
            return new JsonResponse(['message' => 'Invalid credentials.'], JsonResponse::HTTP_UNAUTHORIZED);
        }

        if (!$user->isActive()) {
            return new JsonResponse(['message' => 'User account is disabled.'], JsonResponse::HTTP_FORBIDDEN);
        }

        if (!$user->isPasswordLoginEnabled()) {
            return new JsonResponse(['message' => 'Esta conta ainda não possui senha local. Valide seu e-mail e crie uma senha após entrar com Google.'], JsonResponse::HTTP_FORBIDDEN);
        }

        if (!$this->passwordHasher->isPasswordValid($user, $password)) {
            return new JsonResponse(['message' => 'Invalid credentials.'], JsonResponse::HTTP_UNAUTHORIZED);
        }

        if ($this->passwordHasher->needsRehash($user)) {
            $user->setPassword($this->passwordHasher->hashPassword($user, $password));
            $this->entityManager->flush();
        }

        return $this->createAuthenticatedResponse($user, $request);
    }

    #[Route('/register', name: 'auth_register', methods: ['POST'])]
    public function register(Request $request): JsonResponse
    {
        $payload = $this->decodeJson($request);
        if ($payload === null) {
            return new JsonResponse(['message' => 'Invalid JSON payload.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $email = mb_strtolower(trim((string) ($payload['email'] ?? '')));
        $password = (string) ($payload['password'] ?? '');
        $confirmPassword = (string) ($payload['confirmPassword'] ?? '');

        if ($email === '' || $password === '') {
            return new JsonResponse(['message' => 'Email and password are required.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return new JsonResponse(['message' => 'A valid email is required.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        if (mb_strlen($password) < 8) {
            return new JsonResponse(['message' => 'Password must contain at least 8 characters.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        if ($confirmPassword !== '' && $password !== $confirmPassword) {
            return new JsonResponse(['message' => 'Password confirmation does not match.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        if ($this->userRepository->findOneByEmail($email) !== null) {
            return new JsonResponse(['message' => 'There is already an account with this email.'], JsonResponse::HTTP_CONFLICT);
        }

        $user = (new User())
            ->setEmail($email)
            ->setRoles(['ROLE_USER'])
            ->setIsActive(true);

        $user->setPassword($this->passwordHasher->hashPassword($user, $password));

        try {
            $this->entityManager->persist($user);
            $this->userEmailManager->ensureEmail($user, $email, ['system'], true, true);
            $this->entityManager->flush();
        } catch (UserEmailConflictException $exception) {
            return new JsonResponse(['message' => $exception->getMessage()], JsonResponse::HTTP_CONFLICT);
        }

        return $this->createAuthenticatedResponse($user, $request);
    }

    #[Route('/google', name: 'auth_google', methods: ['POST'])]
    public function googleLogin(Request $request): JsonResponse
    {
        $payload = $this->decodeJson($request);
        if ($payload === null) {
            return new JsonResponse(['message' => 'Invalid JSON payload.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $credential = trim((string) ($payload['credential'] ?? ''));
        if ($credential === '') {
            return new JsonResponse(['message' => 'Google credential is required.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        try {
            $googleIdentity = $this->googleIdentityVerifier->verifyIdToken($credential);
            $user = $this->resolveGoogleUser($googleIdentity);
        } catch (GoogleOAuthConfigurationException $exception) {
            return new JsonResponse(['message' => $exception->getMessage()], JsonResponse::HTTP_INTERNAL_SERVER_ERROR);
        } catch (GoogleAccountLinkException|UserEmailConflictException $exception) {
            return new JsonResponse(['message' => $exception->getMessage()], JsonResponse::HTTP_CONFLICT);
        } catch (GoogleTokenVerificationException $exception) {
            return new JsonResponse(['message' => $exception->getMessage()], JsonResponse::HTTP_UNAUTHORIZED);
        } catch (\RuntimeException $exception) {
            return new JsonResponse(['message' => $exception->getMessage()], JsonResponse::HTTP_INTERNAL_SERVER_ERROR);
        }

        if (!$user->isActive()) {
            return new JsonResponse(['message' => 'User account is disabled.'], JsonResponse::HTTP_FORBIDDEN);
        }

        return $this->createAuthenticatedResponse($user, $request);
    }

    private function createAuthenticatedResponse(User $user, Request $request): JsonResponse
    {
        $accessToken = $this->accessTokenManager->issueForUserFromRequest($user, $request);
        $issuedRefreshToken = $this->refreshTokenManager->issue($user, $request);

        $response = new JsonResponse([
            'token' => $accessToken,
            'token_type' => 'Bearer',
            'expires_in' => $this->accessTokenTtl,
            'user' => $this->userPayloadBuilder->build($user),
        ]);

        $response->headers->setCookie($this->buildRefreshCookie($request, $issuedRefreshToken->plainToken, $issuedRefreshToken->expiresAt));
        $this->appendLegacyRefreshCookieCleanup($response, $request);

        return $response;
    }

    private function resolveGoogleUser(GoogleIdentity $googleIdentity): User
    {
        $user = $this->userRepository->findOneByGoogleSubject($googleIdentity->subject);
        if ($user !== null) {
            $this->userEmailManager->ensureEmail($user, $googleIdentity->email, ['google'], true, false);
            $newValidationCode = null;
            if ($this->googlePasswordSetupManager->requiresPasswordSetup($user)) {
                $newValidationCode = $this->googlePasswordSetupManager->ensureValidCode($user);
            }
            $this->entityManager->flush();

            if ($newValidationCode !== null) {
                $this->googlePasswordSetupMailer->sendCode($user, $newValidationCode);
            }

            return $user;
        }

        $existingUser = $this->userRepository->findOneByEmail($googleIdentity->email);
        if ($existingUser !== null) {
            throw new GoogleAccountLinkException('There is already a local account with this email. Sign in with email and password before linking Google.');
        }

        return $this->createGoogleUser($googleIdentity);
    }

    private function createGoogleUser(GoogleIdentity $googleIdentity): User
    {
        $user = (new User())
            ->setEmail($googleIdentity->email)
            ->setGoogleSubject($googleIdentity->subject)
            ->setRoles(['ROLE_USER'])
            ->setIsActive(true)
            ->setPasswordLoginEnabled(false);

        $user->setPassword($this->passwordHasher->hashPassword($user, bin2hex(random_bytes(32))));

        $this->entityManager->persist($user);
        $this->userEmailManager->ensureEmail($user, $googleIdentity->email, ['google'], true, true);
        $verificationCode = $this->googlePasswordSetupManager->issueCode($user);
        $this->entityManager->flush();
        $this->googlePasswordSetupMailer->sendCode($user, $verificationCode);

        return $user;
    }

    #[Route('/google/password-setup/send-code', name: 'auth_google_password_setup_send_code', methods: ['POST'])]
    public function sendGooglePasswordSetupCode(#[CurrentUser] ?User $user): JsonResponse
    {
        if ($user === null) {
            return new JsonResponse(['message' => 'Unauthorized.'], JsonResponse::HTTP_UNAUTHORIZED);
        }

        try {
            $verificationCode = $this->googlePasswordSetupManager->issueCode($user);
            $this->entityManager->flush();
            $this->googlePasswordSetupMailer->sendCode($user, $verificationCode);
        } catch (\InvalidArgumentException $exception) {
            return new JsonResponse(['message' => $exception->getMessage()], JsonResponse::HTTP_BAD_REQUEST);
        } catch (\RuntimeException $exception) {
            return new JsonResponse(['message' => $exception->getMessage()], JsonResponse::HTTP_INTERNAL_SERVER_ERROR);
        }

        return new JsonResponse([
            'message' => 'Código enviado por e-mail com sucesso.',
            'user' => $this->userPayloadBuilder->build($user),
        ]);
    }

    #[Route('/google/password-setup/verify-code', name: 'auth_google_password_setup_verify_code', methods: ['POST'])]
    public function verifyGooglePasswordSetupCode(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if ($user === null) {
            return new JsonResponse(['message' => 'Unauthorized.'], JsonResponse::HTTP_UNAUTHORIZED);
        }

        $payload = $this->decodeJson($request);
        if ($payload === null) {
            return new JsonResponse(['message' => 'Invalid JSON payload.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $code = trim((string) ($payload['code'] ?? ''));

        try {
            $this->googlePasswordSetupManager->verifyCode($user, $code);
            $this->entityManager->flush();
        } catch (\InvalidArgumentException $exception) {
            return new JsonResponse(['message' => $exception->getMessage()], JsonResponse::HTTP_BAD_REQUEST);
        }

        return new JsonResponse([
            'message' => 'E-mail validado com sucesso. Agora você já pode criar sua senha.',
            'user' => $this->userPayloadBuilder->build($user),
        ]);
    }

    #[Route('/google/password-setup/set-password', name: 'auth_google_password_setup_set_password', methods: ['POST'])]
    public function setGooglePassword(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if ($user === null) {
            return new JsonResponse(['message' => 'Unauthorized.'], JsonResponse::HTTP_UNAUTHORIZED);
        }

        $payload = $this->decodeJson($request);
        if ($payload === null) {
            return new JsonResponse(['message' => 'Invalid JSON payload.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $password = (string) ($payload['password'] ?? '');
        $confirmPassword = (string) ($payload['confirmPassword'] ?? '');

        if (mb_strlen($password) < 8) {
            return new JsonResponse(['message' => 'A senha deve ter no mínimo 8 caracteres.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        if ($confirmPassword !== '' && $password !== $confirmPassword) {
            return new JsonResponse(['message' => 'A confirmação de senha não confere.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        if (!$this->googlePasswordSetupManager->isEmailCodeValidated($user)) {
            return new JsonResponse(['message' => 'Valide o código recebido por e-mail antes de criar sua senha.'], JsonResponse::HTTP_FORBIDDEN);
        }

        $user
            ->setPassword($this->passwordHasher->hashPassword($user, $password))
            ->setPasswordLoginEnabled(true);
        $this->googlePasswordSetupManager->clearPasswordSetupState($user);
        $this->entityManager->flush();

        return new JsonResponse([
            'message' => 'Senha criada com sucesso. Agora você também pode entrar com e-mail e senha.',
            'token' => $this->accessTokenManager->issueForUserFromRequest($user, $request),
            'token_type' => 'Bearer',
            'expires_in' => $this->accessTokenTtl,
            'user' => $this->userPayloadBuilder->build($user),
        ]);
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
            $response->headers->setCookie($this->buildClearRefreshCookie($request));
            $this->appendLegacyRefreshCookieCleanup($response, $request);

            return $response;
        }

        $accessToken = $this->accessTokenManager->issueForUserFromRequest($issuedRefreshToken->user, $request);

        $response = new JsonResponse([
            'token' => $accessToken,
            'token_type' => 'Bearer',
            'expires_in' => $this->accessTokenTtl,
            'user' => $this->userPayloadBuilder->build($issuedRefreshToken->user),
        ]);
        $response->headers->setCookie($this->buildRefreshCookie($request, $issuedRefreshToken->plainToken, $issuedRefreshToken->expiresAt));
        $this->appendLegacyRefreshCookieCleanup($response, $request);

        return $response;
    }

    #[Route('/session/restore-available', name: 'auth_session_restore_available', methods: ['GET'])]
    public function sessionRestoreAvailable(Request $request): JsonResponse
    {
        $refreshToken = trim((string) $request->cookies->get($this->refreshCookieName, ''));

        return new JsonResponse([
            'restoreAvailable' => $refreshToken !== '',
        ]);
    }

    #[Route('/logout', name: 'auth_logout', methods: ['POST'])]
    public function logout(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        $this->accessTokenManager->blacklistFromRequest($request, $user);

        $refreshToken = $request->cookies->get($this->refreshCookieName);
        $this->refreshTokenManager->revokeByPlainToken($refreshToken);
        $sessionCookieName = null;

        if ($request->hasSession()) {
            $session = $request->getSession();
            $sessionCookieName = $session->getName();
            $session->invalidate();
        }

        $response = new JsonResponse(['message' => 'Logged out successfully.']);
        $response->headers->setCookie($this->buildClearRefreshCookie($request));
        $this->appendLegacyRefreshCookieCleanup($response, $request);

        if (is_string($sessionCookieName) && trim($sessionCookieName) !== '') {
            $response->headers->setCookie($this->buildClearSessionCookie($sessionCookieName));
        }

        return $response;
    }

    #[Route('/me', name: 'auth_me', methods: ['GET'])]
    public function me(#[CurrentUser] ?User $user): JsonResponse
    {
        if ($user === null) {
            return new JsonResponse(['message' => 'Unauthorized.'], JsonResponse::HTTP_UNAUTHORIZED);
        }

        return new JsonResponse(['user' => $this->userPayloadBuilder->build($user)]);
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

    private function buildRefreshCookie(Request $request, string $plainToken, \DateTimeImmutable $expiresAt): Cookie
    {
        return Cookie::create($this->refreshCookieName)
            ->withValue($plainToken)
            ->withPath($this->resolveRefreshCookiePath($request))
            ->withExpires($expiresAt)
            ->withHttpOnly(true)
            ->withSecure($this->refreshCookieSecure)
            ->withSameSite($this->resolveSameSite($this->refreshCookieSameSite));
    }

    private function buildClearRefreshCookie(Request $request): Cookie
    {
        return Cookie::create($this->refreshCookieName)
            ->withValue('')
            ->withPath($this->resolveRefreshCookiePath($request))
            ->withExpires(new \DateTimeImmutable('-1 day'))
            ->withHttpOnly(true)
            ->withSecure($this->refreshCookieSecure)
            ->withSameSite($this->resolveSameSite($this->refreshCookieSameSite));
    }

    private function buildClearRefreshCookieForPath(string $cookiePath): Cookie
    {
        return Cookie::create($this->refreshCookieName)
            ->withValue('')
            ->withPath($cookiePath)
            ->withExpires(new \DateTimeImmutable('-1 day'))
            ->withHttpOnly(true)
            ->withSecure($this->refreshCookieSecure)
            ->withSameSite($this->resolveSameSite($this->refreshCookieSameSite));
    }

    private function buildClearSessionCookie(string $cookieName): Cookie
    {
        return Cookie::create(trim($cookieName))
            ->withValue('')
            ->withPath('/')
            ->withExpires(new \DateTimeImmutable('-1 day'))
            ->withHttpOnly(true)
            ->withSecure($this->refreshCookieSecure)
            ->withSameSite($this->resolveSameSite($this->refreshCookieSameSite));
    }

    private function resolveRefreshCookiePath(Request $request): string
    {
        $forwardedPrefix = trim((string) $request->headers->get('X-Forwarded-Prefix', ''), '/');
        if ($forwardedPrefix !== '') {
            return sprintf('/%s/auth', $forwardedPrefix);
        }

        $basePath = trim((string) $request->getBasePath(), '/');
        if ($basePath !== '') {
            return sprintf('/%s/auth', $basePath);
        }

        $pathInfo = '/' . ltrim((string) $request->getPathInfo(), '/');
        $authSegmentPosition = strpos($pathInfo, '/auth');
        if ($authSegmentPosition !== false) {
            $prefixPath = trim(substr($pathInfo, 0, $authSegmentPosition), '/');
            if ($prefixPath !== '') {
                return sprintf('/%s/auth', $prefixPath);
            }
        }

        return '/auth';
    }

    private function appendLegacyRefreshCookieCleanup(JsonResponse $response, Request $request): void
    {
        $activePath = $this->resolveRefreshCookiePath($request);

        foreach ($this->resolveLegacyRefreshCookiePaths($request) as $legacyPath) {
            if ($legacyPath === $activePath) {
                continue;
            }

            $response->headers->setCookie($this->buildClearRefreshCookieForPath($legacyPath));
        }
    }

    /**
     * @return string[]
     */
    private function resolveLegacyRefreshCookiePaths(Request $request): array
    {
        $forwardedPrefix = '/' . trim((string) $request->headers->get('X-Forwarded-Prefix', ''), '/');
        if ($forwardedPrefix === '//') {
            $forwardedPrefix = '/';
        }

        $basePath = '/' . trim((string) $request->getBasePath(), '/');
        if ($basePath === '//') {
            $basePath = '/';
        }

        $pathInfo = '/' . ltrim((string) $request->getPathInfo(), '/');
        $authSegmentPosition = strpos($pathInfo, '/auth');
        $prefixFromPathInfo = '/';
        if ($authSegmentPosition !== false) {
            $prefixFromPathInfo = rtrim(substr($pathInfo, 0, $authSegmentPosition), '/');
            if ($prefixFromPathInfo === '') {
                $prefixFromPathInfo = '/';
            }
        }

        $candidatePaths = [
            '/',
            '/auth',
            $forwardedPrefix,
            rtrim($forwardedPrefix, '/') . '/auth',
            $basePath,
            rtrim($basePath, '/') . '/auth',
            $prefixFromPathInfo,
            rtrim($prefixFromPathInfo, '/') . '/auth',
        ];

        $normalizedPaths = [];
        foreach ($candidatePaths as $candidatePath) {
            $normalizedPath = '/' . trim((string) $candidatePath, '/');
            if ($normalizedPath === '//') {
                $normalizedPath = '/';
            }

            if (!in_array($normalizedPath, $normalizedPaths, true)) {
                $normalizedPaths[] = $normalizedPath;
            }
        }

        return $normalizedPaths;
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
