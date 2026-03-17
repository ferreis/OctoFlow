<?php

namespace App\Controller;

use App\Account\Exception\UserEmailConflictException;
use App\Account\UserPayloadBuilder;
use App\Entity\User;
use App\Github\Exception\GithubApiException;
use App\Github\Exception\GithubConfigurationException;
use App\Github\Exception\GithubGraphQLException;
use App\Github\GithubAssignedIssueService;
use App\Github\GithubLinkedEmailService;
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
        private readonly GithubAssignedIssueService $assignedIssueService,
        private readonly GithubProfileService $profileService,
        private readonly GithubLinkedEmailService $linkedEmailService,
        private readonly UserPayloadBuilder $userPayloadBuilder,
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
    public function workspace(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if ($user === null) {
            return new JsonResponse(['message' => 'Unauthorized.'], JsonResponse::HTTP_UNAUTHORIZED);
        }

        $repositoryOwner = $this->optionalQueryString($request->query->get('repositoryOwner'));
        $repositoryName = $this->optionalQueryString($request->query->get('repositoryName'));

        try {
            return new JsonResponse($this->workspaceService->fetchWorkspace($user, $repositoryOwner, $repositoryName));
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

    #[Route('/issues/assigned', name: 'github_issue_assigned_list', methods: ['GET'])]
    public function assignedIssues(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if ($user === null) {
            return new JsonResponse(['message' => 'Unauthorized.'], JsonResponse::HTTP_UNAUTHORIZED);
        }

        $repositoryOwner = $this->optionalQueryString($request->query->get('repositoryOwner'));
        $repositoryName = $this->optionalQueryString($request->query->get('repositoryName'));
        $scope = $this->optionalQueryString($request->query->get('scope')) ?? 'all';

        try {
            return new JsonResponse($this->assignedIssueService->fetchIssues($user, $repositoryOwner, $repositoryName, $scope));
        } catch (\InvalidArgumentException $exception) {
            return new JsonResponse(['message' => $exception->getMessage()], JsonResponse::HTTP_BAD_REQUEST);
        } catch (GithubConfigurationException $exception) {
            return new JsonResponse(['message' => $exception->getMessage()], JsonResponse::HTTP_SERVICE_UNAVAILABLE);
        } catch (GithubGraphQLException $exception) {
            return new JsonResponse(['message' => $exception->getMessage()], JsonResponse::HTTP_BAD_GATEWAY);
        }
    }

    #[Route('/issues/cache', name: 'github_issue_cache_list', methods: ['GET'])]
    public function cachedIssues(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if ($user === null) {
            return new JsonResponse(['message' => 'Unauthorized.'], JsonResponse::HTTP_UNAUTHORIZED);
        }

        $repositoryOwner = $this->optionalQueryString($request->query->get('repositoryOwner'));
        $repositoryName = $this->optionalQueryString($request->query->get('repositoryName'));
        $scope = $this->optionalQueryString($request->query->get('scope')) ?? 'all';

        try {
            return new JsonResponse($this->assignedIssueService->fetchCachedIssues($user, $repositoryOwner, $repositoryName, $scope));
        } catch (\InvalidArgumentException $exception) {
            return new JsonResponse(['message' => $exception->getMessage()], JsonResponse::HTTP_BAD_REQUEST);
        }
    }

    #[Route('/issues/{issueId}', name: 'github_issue_show', methods: ['GET'])]
    public function issue(string $issueId, #[CurrentUser] ?User $user): JsonResponse
    {
        if ($user === null) {
            return new JsonResponse(['message' => 'Unauthorized.'], JsonResponse::HTTP_UNAUTHORIZED);
        }

        try {
            return new JsonResponse($this->assignedIssueService->fetchIssue($user, $issueId));
        } catch (\InvalidArgumentException $exception) {
            return new JsonResponse(['message' => $exception->getMessage()], JsonResponse::HTTP_BAD_REQUEST);
        } catch (GithubConfigurationException $exception) {
            return new JsonResponse(['message' => $exception->getMessage()], JsonResponse::HTTP_SERVICE_UNAVAILABLE);
        } catch (GithubGraphQLException $exception) {
            return new JsonResponse(['message' => $exception->getMessage()], JsonResponse::HTTP_BAD_GATEWAY);
        }
    }

    #[Route('/issues/{issueId}', name: 'github_issue_update', methods: ['PATCH', 'PUT'])]
    public function updateIssue(string $issueId, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if ($user === null) {
            return new JsonResponse(['message' => 'Unauthorized.'], JsonResponse::HTTP_UNAUTHORIZED);
        }

        $payload = $this->decodeJson($request);
        if ($payload === null) {
            return new JsonResponse(['message' => 'Invalid JSON payload.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        try {
            return new JsonResponse(['item' => $this->assignedIssueService->updateIssue($user, $issueId, $payload)]);
        } catch (\InvalidArgumentException $exception) {
            return new JsonResponse(['message' => $exception->getMessage()], JsonResponse::HTTP_BAD_REQUEST);
        } catch (GithubConfigurationException $exception) {
            return new JsonResponse(['message' => $exception->getMessage()], JsonResponse::HTTP_SERVICE_UNAVAILABLE);
        } catch (GithubGraphQLException $exception) {
            return new JsonResponse(['message' => $exception->getMessage()], JsonResponse::HTTP_BAD_GATEWAY);
        }
    }

    #[Route('/emails/link', name: 'github_emails_link', methods: ['POST'])]
    public function linkEmails(#[CurrentUser] ?User $user): JsonResponse
    {
        if ($user === null) {
            return new JsonResponse(['message' => 'Unauthorized.'], JsonResponse::HTTP_UNAUTHORIZED);
        }

        try {
            $linkedEmails = $this->linkedEmailService->linkVerifiedEmails($user);
        } catch (UserEmailConflictException $exception) {
            return new JsonResponse(['message' => $exception->getMessage()], JsonResponse::HTTP_CONFLICT);
        } catch (\InvalidArgumentException $exception) {
            return new JsonResponse(['message' => $exception->getMessage()], JsonResponse::HTTP_BAD_REQUEST);
        } catch (GithubConfigurationException $exception) {
            return new JsonResponse(['message' => $exception->getMessage()], JsonResponse::HTTP_SERVICE_UNAVAILABLE);
        } catch (GithubApiException $exception) {
            return new JsonResponse(['message' => $exception->getMessage()], JsonResponse::HTTP_BAD_GATEWAY);
        }

        return new JsonResponse([
            'message' => 'GitHub emails linked successfully.',
            'importedCount' => count($linkedEmails),
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

    private function optionalQueryString(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }
}
