<?php

namespace App\Controller;

use App\Account\AccountPasswordChangeMailer;
use App\Account\AccountPasswordChangeManager;
use App\Account\Exception\UserEmailConflictException;
use App\Account\UserEmailManager;
use App\Account\UserAvatarManager;
use App\Account\UserPayloadBuilder;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Security\Google\Exception\GoogleAccountLinkException;
use App\Security\Google\Exception\GoogleOAuthConfigurationException;
use App\Security\Google\Exception\GoogleTokenVerificationException;
use App\Security\Google\GoogleIdentity;
use App\Security\Google\GoogleIdentityVerifier;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[AsController]
#[IsGranted('IS_AUTHENTICATED_FULLY')]
#[Route('/auth')]
final class AccountEmailController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserRepository $userRepository,
        private readonly UserEmailManager $userEmailManager,
        private readonly UserAvatarManager $userAvatarManager,
        private readonly UserPayloadBuilder $userPayloadBuilder,
        private readonly AccountPasswordChangeManager $accountPasswordChangeManager,
        private readonly AccountPasswordChangeMailer $accountPasswordChangeMailer,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly GoogleIdentityVerifier $googleIdentityVerifier,
        private readonly JWTTokenManagerInterface $jwtTokenManager,
        #[Autowire('%env(int:JWT_TOKEN_TTL)%')]
        private readonly int $accessTokenTtl,
    ) {
    }

    #[Route('/emails', name: 'auth_emails_list', methods: ['GET'])]
    public function list(#[CurrentUser] ?User $user): JsonResponse
    {
        if ($user === null) {
            return new JsonResponse(['message' => 'Unauthorized.'], JsonResponse::HTTP_UNAUTHORIZED);
        }

        return new JsonResponse([
            'token' => $this->jwtTokenManager->create($user),
            'token_type' => 'Bearer',
            'expires_in' => $this->accessTokenTtl,
            'user' => $this->userPayloadBuilder->build($user),
        ]);
    }

    #[Route('/emails/default', name: 'auth_emails_default', methods: ['PATCH'])]
    public function updateDefaultEmail(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if ($user === null) {
            return new JsonResponse(['message' => 'Unauthorized.'], JsonResponse::HTTP_UNAUTHORIZED);
        }

        $payload = $this->decodeJson($request);
        if ($payload === null) {
            return new JsonResponse(['message' => 'Invalid JSON payload.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $email = trim((string) ($payload['email'] ?? ''));
        if ($email === '') {
            return new JsonResponse(['message' => 'The linked email to set as default is required.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        try {
            $this->userEmailManager->setPrimaryEmail($user, $email);
            $this->entityManager->flush();
        } catch (\InvalidArgumentException $exception) {
            return new JsonResponse(['message' => $exception->getMessage()], JsonResponse::HTTP_BAD_REQUEST);
        }

        return new JsonResponse([
            'token' => $this->jwtTokenManager->create($user),
            'token_type' => 'Bearer',
            'expires_in' => $this->accessTokenTtl,
            'user' => $this->userPayloadBuilder->build($user),
        ]);
    }

    #[Route('/google/link', name: 'auth_google_link', methods: ['POST'])]
    public function linkGoogle(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if ($user === null) {
            return new JsonResponse(['message' => 'Unauthorized.'], JsonResponse::HTTP_UNAUTHORIZED);
        }

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
            $this->linkGoogleIdentity($user, $googleIdentity);
            $this->entityManager->flush();
        } catch (GoogleOAuthConfigurationException $exception) {
            return new JsonResponse(['message' => $exception->getMessage()], JsonResponse::HTTP_INTERNAL_SERVER_ERROR);
        } catch (GoogleTokenVerificationException $exception) {
            return new JsonResponse(['message' => $exception->getMessage()], JsonResponse::HTTP_UNAUTHORIZED);
        } catch (UserEmailConflictException|GoogleAccountLinkException $exception) {
            return new JsonResponse(['message' => $exception->getMessage()], JsonResponse::HTTP_CONFLICT);
        } catch (\InvalidArgumentException $exception) {
            return new JsonResponse(['message' => $exception->getMessage()], JsonResponse::HTTP_BAD_REQUEST);
        }

        return new JsonResponse([
            'message' => 'Google account linked successfully.',
            'user' => $this->userPayloadBuilder->build($user),
        ]);
    }

    #[Route('/profile/avatar', name: 'auth_profile_avatar_upload', methods: ['POST'])]
    public function uploadAvatar(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if ($user === null) {
            return new JsonResponse(['message' => 'Unauthorized.'], JsonResponse::HTTP_UNAUTHORIZED);
        }

        $uploadedAvatar = $request->files->get('avatar');
        if (!$uploadedAvatar instanceof UploadedFile) {
            return new JsonResponse(['message' => 'Envie a imagem no campo avatar.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        try {
            $this->userAvatarManager->storeUploadedAvatar($user, $uploadedAvatar);
            $this->entityManager->flush();
        } catch (\InvalidArgumentException $exception) {
            return new JsonResponse(['message' => $exception->getMessage()], JsonResponse::HTTP_BAD_REQUEST);
        } catch (\RuntimeException $exception) {
            return new JsonResponse(['message' => $exception->getMessage()], JsonResponse::HTTP_INTERNAL_SERVER_ERROR);
        }

        return new JsonResponse([
            'message' => 'Imagem de perfil atualizada com sucesso.',
            'token' => $this->jwtTokenManager->create($user),
            'token_type' => 'Bearer',
            'expires_in' => $this->accessTokenTtl,
            'user' => $this->userPayloadBuilder->build($user),
        ]);
    }

    #[Route('/profile/avatar', name: 'auth_profile_avatar_delete', methods: ['DELETE'])]
    public function deleteAvatar(#[CurrentUser] ?User $user): JsonResponse
    {
        if ($user === null) {
            return new JsonResponse(['message' => 'Unauthorized.'], JsonResponse::HTTP_UNAUTHORIZED);
        }

        try {
            $this->userAvatarManager->removeAvatar($user);
            $this->entityManager->flush();
        } catch (\RuntimeException $exception) {
            return new JsonResponse(['message' => $exception->getMessage()], JsonResponse::HTTP_INTERNAL_SERVER_ERROR);
        }

        return new JsonResponse([
            'message' => 'Imagem de perfil removida com sucesso.',
            'token' => $this->jwtTokenManager->create($user),
            'token_type' => 'Bearer',
            'expires_in' => $this->accessTokenTtl,
            'user' => $this->userPayloadBuilder->build($user),
        ]);
    }

    #[Route('/password-change/send-code', name: 'auth_password_change_send_code', methods: ['POST'])]
    public function sendPasswordChangeCode(#[CurrentUser] ?User $user): JsonResponse
    {
        if ($user === null) {
            return new JsonResponse(['message' => 'Unauthorized.'], JsonResponse::HTTP_UNAUTHORIZED);
        }

        try {
            $verificationCode = $this->accountPasswordChangeManager->issueCode($user);
            $this->entityManager->flush();
            $this->accountPasswordChangeMailer->sendCode($user, $verificationCode);
        } catch (\RuntimeException $exception) {
            return new JsonResponse(['message' => $exception->getMessage()], JsonResponse::HTTP_INTERNAL_SERVER_ERROR);
        }

        return new JsonResponse([
            'message' => 'Código enviado para seu e-mail principal.',
            'codeExpiresAt' => $user->getPasswordChangeCodeExpiresAt()?->format(\DateTimeInterface::ATOM),
            'token' => $this->jwtTokenManager->create($user),
            'token_type' => 'Bearer',
            'expires_in' => $this->accessTokenTtl,
            'user' => $this->userPayloadBuilder->build($user),
        ]);
    }

    #[Route('/password-change/verify-code', name: 'auth_password_change_verify_code', methods: ['POST'])]
    public function verifyPasswordChangeCode(Request $request, #[CurrentUser] ?User $user): JsonResponse
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
            $this->accountPasswordChangeManager->verifyCode($user, $code);
            $this->entityManager->flush();
        } catch (\InvalidArgumentException $exception) {
            return new JsonResponse(['message' => $exception->getMessage()], JsonResponse::HTTP_BAD_REQUEST);
        }

        return new JsonResponse([
            'message' => 'Código validado. Agora defina sua nova senha.',
            'token' => $this->jwtTokenManager->create($user),
            'token_type' => 'Bearer',
            'expires_in' => $this->accessTokenTtl,
            'user' => $this->userPayloadBuilder->build($user),
        ]);
    }

    #[Route('/password-change/set-password', name: 'auth_password_change_set_password', methods: ['POST'])]
    public function setPasswordFromProfile(Request $request, #[CurrentUser] ?User $user): JsonResponse
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

        try {
            $this->accountPasswordChangeManager->ensureVerifiedForPasswordChange($user);
        } catch (\InvalidArgumentException $exception) {
            return new JsonResponse(['message' => $exception->getMessage()], JsonResponse::HTTP_FORBIDDEN);
        }

        $user
            ->setPassword($this->passwordHasher->hashPassword($user, $password))
            ->setPasswordLoginEnabled(true);
        $this->accountPasswordChangeManager->clearState($user);
        $this->entityManager->flush();

        return new JsonResponse([
            'message' => 'Senha atualizada com sucesso.',
            'token' => $this->jwtTokenManager->create($user),
            'token_type' => 'Bearer',
            'expires_in' => $this->accessTokenTtl,
            'user' => $this->userPayloadBuilder->build($user),
        ]);
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

    private function linkGoogleIdentity(User $user, GoogleIdentity $googleIdentity): void
    {
        $userAlreadyLinkedToGoogle = $this->userRepository->findOneByGoogleSubject($googleIdentity->subject);
        if ($userAlreadyLinkedToGoogle !== null && $userAlreadyLinkedToGoogle->getId() !== $user->getId()) {
            throw new GoogleAccountLinkException('Esta conta do Google já está vinculada a outro usuário.');
        }

        $existingGoogleSubject = $user->getGoogleSubject();
        if ($existingGoogleSubject !== null && $existingGoogleSubject !== '' && $existingGoogleSubject !== $googleIdentity->subject) {
            throw new GoogleAccountLinkException('Já existe uma conta Google diferente vinculada a este usuário..');
        }

        $this->userEmailManager->ensureEmail($user, $googleIdentity->email, ['google'], true, false);
        $user->setGoogleSubject($googleIdentity->subject);
    }
}
