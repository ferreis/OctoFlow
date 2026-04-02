<?php

namespace App\Tests\Unit\Controller;

use App\Account\GooglePasswordSetupMailer;
use App\Account\GooglePasswordSetupManager;
use App\Account\UserEmailManager;
use App\Account\UserPayloadBuilder;
use App\Controller\AuthController;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Security\AccessTokenManagerInterface;
use App\Security\CsrfTokenManager;
use App\Security\Google\GoogleIdentityVerifier;
use App\Security\Google\GoogleTokenInfoClientInterface;
use App\Security\RefreshTokenManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class AuthControllerGooglePasswordSetupTest extends TestCase
{
    public function testLoginBlocksGoogleAccountWithoutLocalPassword(): void
    {
        $fixture = $this->createControllerFixture();
        $controller = $fixture['controller'];
        $userRepository = $fixture['userRepository'];

        $googleOnlyUser = (new User())
            ->setEmail('google-user@example.com')
            ->setGoogleSubject('google-subject-123')
            ->setPasswordLoginEnabled(false)
            ->setIsActive(true);

        $userRepository
            ->expects($this->once())
            ->method('findOneByEmail')
            ->with('google-user@example.com')
            ->willReturn($googleOnlyUser);

        $request = Request::create(
            '/auth/login',
            'POST',
            content: json_encode([
                'email' => 'google-user@example.com',
                'password' => 'plain-password-123',
            ], \JSON_THROW_ON_ERROR),
        );

        $response = $controller->login($request);
        $payload = json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(
            'Esta conta ainda não possui senha local. Valide seu e-mail e crie uma senha após entrar com Google.',
            $payload['message'],
        );
    }

    public function testSetGooglePasswordRequiresValidatedEmailCode(): void
    {
        $fixture = $this->createControllerFixture();
        $controller = $fixture['controller'];

        $googleOnlyUser = (new User())
            ->setEmail('google-user@example.com')
            ->setGoogleSubject('google-subject-123')
            ->setPasswordLoginEnabled(false)
            ->setIsActive(true);

        $request = Request::create(
            '/auth/google/password-setup/set-password',
            'POST',
            content: json_encode([
                'password' => 'new-password-123',
                'confirmPassword' => 'new-password-123',
            ], \JSON_THROW_ON_ERROR),
        );

        $response = $controller->setGooglePassword($request, $googleOnlyUser);
        $payload = json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('Valide o código recebido por e-mail antes de criar sua senha.', $payload['message']);
    }

    public function testVerifyGooglePasswordSetupCodeRejectsInvalidCode(): void
    {
        $fixture = $this->createControllerFixture();
        $controller = $fixture['controller'];
        $googlePasswordSetupManager = $fixture['googlePasswordSetupManager'];

        $googleOnlyUser = (new User())
            ->setEmail('google-user@example.com')
            ->setGoogleSubject('google-subject-123')
            ->setPasswordLoginEnabled(false)
            ->setIsActive(true);

        $googlePasswordSetupManager->issueCode($googleOnlyUser);

        $request = Request::create(
            '/auth/google/password-setup/verify-code',
            'POST',
            content: json_encode([
                'code' => 'WRONG-CODE',
            ], \JSON_THROW_ON_ERROR),
        );

        $response = $controller->verifyGooglePasswordSetupCode($request, $googleOnlyUser);
        $payload = json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('Código inválido. Confira o código e tente novamente.', $payload['message']);
    }

    public function testVerifyGooglePasswordSetupCodeRejectsExpiredCode(): void
    {
        $fixture = $this->createControllerFixture();
        $controller = $fixture['controller'];
        $googlePasswordSetupManager = $fixture['googlePasswordSetupManager'];

        $googleOnlyUser = (new User())
            ->setEmail('google-user@example.com')
            ->setGoogleSubject('google-subject-123')
            ->setPasswordLoginEnabled(false)
            ->setIsActive(true);

        $issuedCode = $googlePasswordSetupManager->issueCode($googleOnlyUser);
        $googleOnlyUser->setGooglePasswordSetupCodeExpiresAt(new \DateTimeImmutable('-5 minutes'));

        $request = Request::create(
            '/auth/google/password-setup/verify-code',
            'POST',
            content: json_encode([
                'code' => $issuedCode,
            ], \JSON_THROW_ON_ERROR),
        );

        $response = $controller->verifyGooglePasswordSetupCode($request, $googleOnlyUser);
        $payload = json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('O código expirou. Solicite um novo código.', $payload['message']);
    }

    public function testSetGooglePasswordEnablesPasswordLoginAfterEmailValidation(): void
    {
        $fixture = $this->createControllerFixture();
        $controller = $fixture['controller'];
        $passwordHasher = $fixture['passwordHasher'];
        $accessTokenManager = $fixture['accessTokenManager'];
        $googlePasswordSetupManager = $fixture['googlePasswordSetupManager'];

        $googleOnlyUser = (new User())
            ->setEmail('google-user@example.com')
            ->setGoogleSubject('google-subject-123')
            ->setPasswordLoginEnabled(false)
            ->setIsActive(true);

        $issuedCode = $googlePasswordSetupManager->issueCode($googleOnlyUser);
        $googlePasswordSetupManager->verifyCode($googleOnlyUser, $issuedCode);

        $passwordHasher
            ->expects($this->once())
            ->method('hashPassword')
            ->with($googleOnlyUser, 'new-password-123')
            ->willReturn('hashed-password-123');

        $accessTokenManager
            ->expects($this->once())
            ->method('issueForUserFromRequest')
            ->with($googleOnlyUser, $this->isInstanceOf(Request::class))
            ->willReturn('jwt-access-token-123');

        $request = Request::create(
            '/auth/google/password-setup/set-password',
            'POST',
            content: json_encode([
                'password' => 'new-password-123',
                'confirmPassword' => 'new-password-123',
            ], \JSON_THROW_ON_ERROR),
        );

        $response = $controller->setGooglePassword($request, $googleOnlyUser);
        $payload = json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('Senha criada com sucesso. Agora você também pode entrar com e-mail e senha.', $payload['message']);
        $this->assertSame('jwt-access-token-123', $payload['token']);
        $this->assertSame('hashed-password-123', $googleOnlyUser->getPassword());
        $this->assertTrue($googleOnlyUser->isPasswordLoginEnabled());
        $this->assertNull($googleOnlyUser->getGooglePasswordSetupCodeHash());
        $this->assertNull($googleOnlyUser->getGooglePasswordSetupCodeExpiresAt());
        $this->assertNull($googleOnlyUser->getGooglePasswordSetupVerifiedAt());
    }

    /**
     * @return array{
     *     controller: AuthController,
     *     userRepository: UserRepository&MockObject,
     *     passwordHasher: UserPasswordHasherInterface&MockObject,
     *     accessTokenManager: AccessTokenManagerInterface&MockObject,
     *     googlePasswordSetupManager: GooglePasswordSetupManager
     * }
     */
    private function createControllerFixture(): array
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $userRepository = $this->createMock(UserRepository::class);
        $passwordHasher = $this->createMock(UserPasswordHasherInterface::class);
        $accessTokenManager = $this->createMock(AccessTokenManagerInterface::class);
        $refreshTokenManager = $this->createMock(RefreshTokenManager::class);

        $csrfTokenManager = new CsrfTokenManager(
            'X-CSRF-Token',
            'X-CSRF-Action',
            300,
            'refresh_token',
        );

        $googleTokenInfoClient = $this->createMock(GoogleTokenInfoClientInterface::class);
        $googleIdentityVerifier = new GoogleIdentityVerifier(
            $googleTokenInfoClient,
            'google-client-id',
            '',
        );

        $userEmailManager = new UserEmailManager($entityManager, $userRepository);
        $googlePasswordSetupManager = new GooglePasswordSetupManager(900);

        $mailer = $this->createMock(MailerInterface::class);
        $googlePasswordSetupMailer = new GooglePasswordSetupMailer(
            $mailer,
            'noreply@example.com',
            'OctoFlow',
            $googlePasswordSetupManager,
        );

        $userPayloadBuilder = new UserPayloadBuilder(
            $userEmailManager,
            $googlePasswordSetupManager,
            $entityManager,
        );

        $controller = new AuthController(
            $userRepository,
            $entityManager,
            $passwordHasher,
            $accessTokenManager,
            $refreshTokenManager,
            $csrfTokenManager,
            $googleIdentityVerifier,
            $userEmailManager,
            $googlePasswordSetupManager,
            $googlePasswordSetupMailer,
            $userPayloadBuilder,
            'refresh_token',
            false,
            'lax',
            3600,
        );

        return [
            'controller' => $controller,
            'userRepository' => $userRepository,
            'passwordHasher' => $passwordHasher,
            'accessTokenManager' => $accessTokenManager,
            'googlePasswordSetupManager' => $googlePasswordSetupManager,
        ];
    }
}
