<?php

namespace App\Controller;

use App\Entity\User;
use App\Ui\UISettingsManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[AsController]
#[IsGranted('IS_AUTHENTICATED_FULLY')]
#[Route('/ui')]
final class UISettingsController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UISettingsManager $uiSettingsManager,
    ) {
    }

    #[Route('/settings', name: 'ui_settings_show', methods: ['GET'])]
    public function show(#[CurrentUser] ?User $user): JsonResponse
    {
        if ($user === null) {
            return new JsonResponse(['message' => 'Unauthorized.'], JsonResponse::HTTP_UNAUTHORIZED);
        }

        $created = $user->getUiSettings() === null;
        $settings = $this->uiSettingsManager->getOrCreate($user);

        if ($created) {
            $this->entityManager->flush();
        }

        return new JsonResponse([
            'settings' => $this->uiSettingsManager->buildPayload($settings),
        ]);
    }

    #[Route('/settings', name: 'ui_settings_update', methods: ['PATCH'])]
    public function update(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if ($user === null) {
            return new JsonResponse(['message' => 'Unauthorized.'], JsonResponse::HTTP_UNAUTHORIZED);
        }

        $payload = $this->decodeJson($request);
        if ($payload === null) {
            return new JsonResponse(['message' => 'Invalid JSON payload.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $settings = $this->uiSettingsManager->getOrCreate($user);
        try {
            $this->uiSettingsManager->updateFromPayload($settings, $payload);
        } catch (\InvalidArgumentException $exception) {
            return new JsonResponse(['message' => $exception->getMessage()], JsonResponse::HTTP_BAD_REQUEST);
        }

        $this->entityManager->flush();

        return new JsonResponse([
            'settings' => $this->uiSettingsManager->buildPayload($settings),
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
}
