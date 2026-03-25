<?php

namespace App\Controller;

use App\Account\UserAvatarManager;
use App\Repository\UserRepository;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Annotation\Route;

#[AsController]
final class AvatarController
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly UserAvatarManager $userAvatarManager,
    ) {
    }

    #[Route('/uploads/avatars/{fileName}', name: 'avatar_show', methods: ['GET'])]
    public function show(string $fileName): BinaryFileResponse|JsonResponse
    {
        $normalizedFileName = trim($fileName);
        if ($normalizedFileName === '' || preg_match('/^[A-Za-z0-9._-]{8,255}$/', $normalizedFileName) !== 1) {
            return new JsonResponse(['message' => 'Avatar nao encontrado.'], JsonResponse::HTTP_NOT_FOUND);
        }

        $avatarPath = '/uploads/avatars/' . $normalizedFileName;
        $ownerUser = $this->userRepository->findOneBy(['avatarPath' => $avatarPath]);
        if ($ownerUser === null) {
            return new JsonResponse(['message' => 'Avatar nao encontrado.'], JsonResponse::HTTP_NOT_FOUND);
        }

        $absoluteAvatarPath = $this->userAvatarManager->resolveAbsolutePathFromAvatarPath($avatarPath);
        if ($absoluteAvatarPath === null || !is_file($absoluteAvatarPath)) {
            return new JsonResponse(['message' => 'Avatar nao encontrado.'], JsonResponse::HTTP_NOT_FOUND);
        }

        $response = new BinaryFileResponse($absoluteAvatarPath);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, $normalizedFileName);
        $response->headers->set('Content-Type', $ownerUser->getAvatarMimeType() ?: 'application/octet-stream');
        $response->setPublic();
        $response->setMaxAge(604800);
        $response->setSharedMaxAge(604800);

        if ($ownerUser->getAvatarUpdatedAt() !== null) {
            $response->setLastModified($ownerUser->getAvatarUpdatedAt());
        }

        return $response;
    }
}
