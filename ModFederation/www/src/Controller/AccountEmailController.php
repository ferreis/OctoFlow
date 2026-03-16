<?php

namespace App\Controller;

use App\Account\Exception\UserEmailConflictException;
use App\Account\UserEmailManager;
use App\Account\UserPayloadBuilder;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Security\Google\Exception\GoogleAccountLinkException;
use App\Security\Google\Exception\GoogleOAuthConfigurationException;
use App\Security\Google\Exception\GoogleTokenVerificationException;
use App\Security\Google\GoogleIdentity;
use App\Security\Google\GoogleIdentityVerifier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
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
        private readonly UserPayloadBuilder $userPayloadBuilder,
        private readonly GoogleIdentityVerifier $googleIdentityVerifier,
    ) {
    }

    #[Route('/emails', name: 'auth_emails_list', methods: ['GET'])]
    public function list(#[CurrentUser] ?User $user): JsonResponse
    {
        if ($user === null) {
            return new JsonResponse(['message' => 'Unauthorized.'], JsonResponse::HTTP_UNAUTHORIZED);
        }

        return new JsonResponse(['user' => $this->userPayloadBuilder->build($user)]);
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

        return new JsonResponse(['user' => $this->userPayloadBuilder->build($user)]);
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
            throw new GoogleAccountLinkException('This Google account is already linked to another user.');
        }

        $existingGoogleSubject = $user->getGoogleSubject();
        if ($existingGoogleSubject !== null && $existingGoogleSubject !== '' && $existingGoogleSubject !== $googleIdentity->subject) {
            throw new GoogleAccountLinkException('A different Google account is already linked to this user.');
        }

        $this->userEmailManager->ensureEmail($user, $googleIdentity->email, ['google'], true, false);
        $user->setGoogleSubject($googleIdentity->subject);
    }
}
