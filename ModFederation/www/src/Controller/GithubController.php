<?php

namespace App\Controller;

use App\Entity\User;
use App\Github\Exception\GithubConfigurationException;
use App\Github\Exception\GithubGraphQLException;
use App\Github\GithubIssueService;
use App\Github\GithubProfileService;
use App\Github\GithubWorkspaceService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[AsController]
#[IsGranted('IS_AUTHENTICATED_FULLY')]
#[Route('/github')]
final class GithubController
{
    public function __construct(
        private readonly GithubWorkspaceService $workspaceService,
        private readonly GithubIssueService $issueService,
        private readonly GithubProfileService $profileService,
    ) {
    }

    #[Route('/profile', name: 'github_profile_show', methods: ['GET'])]
    public function profile(#[CurrentUser] ?User $user): JsonResponse
    {
        if ($user === null) {
            return new JsonResponse(['message' => 'Unauthorized.'], JsonResponse::HTTP_UNAUTHORIZED);
        }

        return new JsonResponse(['profile' => $this->profileService->buildProfilePayload($user)]);
    }

    #[Route('/profile', name: 'github_profile_update', methods: ['PATCH', 'PUT'])]
    public function updateProfile(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if ($user === null) {
            return new JsonResponse(['message' => 'Unauthorized.'], JsonResponse::HTTP_UNAUTHORIZED);
        }

        $payload = $this->decodeJson($request);
        if ($payload === null) {
            return new JsonResponse(['message' => 'Invalid JSON payload.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        try {
            return new JsonResponse(['profile' => $this->profileService->updateProfile($user, $payload)]);
        } catch (\InvalidArgumentException $exception) {
            return new JsonResponse(['message' => $exception->getMessage()], JsonResponse::HTTP_BAD_REQUEST);
        } catch (GithubConfigurationException $exception) {
            return new JsonResponse(['message' => $exception->getMessage()], JsonResponse::HTTP_SERVICE_UNAVAILABLE);
        }
    }

    #[Route('/workspace', name: 'github_workspace', methods: ['GET'])]
    public function workspace(#[CurrentUser] ?User $user): JsonResponse
    {
        if ($user === null) {
            return new JsonResponse(['message' => 'Unauthorized.'], JsonResponse::HTTP_UNAUTHORIZED);
        }

        try {
            return new JsonResponse($this->workspaceService->fetchWorkspace($user));
        } catch (GithubConfigurationException $exception) {
            return new JsonResponse(['message' => $exception->getMessage()], JsonResponse::HTTP_SERVICE_UNAVAILABLE);
        } catch (GithubGraphQLException $exception) {
            return new JsonResponse(['message' => $exception->getMessage()], JsonResponse::HTTP_BAD_GATEWAY);
        }
    }

    #[Route('/issues', name: 'github_issue_create', methods: ['POST'])]
    public function createIssue(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if ($user === null) {
            return new JsonResponse(['message' => 'Unauthorized.'], JsonResponse::HTTP_UNAUTHORIZED);
        }

        $payload = $this->decodeJson($request);
        if ($payload === null) {
            return new JsonResponse(['message' => 'Invalid JSON payload.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        try {
            return new JsonResponse($this->issueService->createIssue($user, $payload), JsonResponse::HTTP_CREATED);
        } catch (\InvalidArgumentException $exception) {
            return new JsonResponse(['message' => $exception->getMessage()], JsonResponse::HTTP_BAD_REQUEST);
        } catch (GithubConfigurationException $exception) {
            return new JsonResponse(['message' => $exception->getMessage()], JsonResponse::HTTP_SERVICE_UNAVAILABLE);
        } catch (GithubGraphQLException $exception) {
            return new JsonResponse(['message' => $exception->getMessage()], JsonResponse::HTTP_BAD_GATEWAY);
        }
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
