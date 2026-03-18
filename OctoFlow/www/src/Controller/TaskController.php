<?php

namespace App\Controller;

use App\Entity\User;
use App\Github\GithubIssueTemplateCatalog;
use App\Task\LocalTaskService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[AsController]
#[IsGranted('IS_AUTHENTICATED_FULLY')]
#[Route('/tasks')]
final class TaskController
{
    public function __construct(
        private readonly GithubIssueTemplateCatalog $templateCatalog,
        private readonly LocalTaskService $localTaskService,
    ) {
    }

    #[Route('/templates', name: 'task_template_list', methods: ['GET'])]
    public function templates(#[CurrentUser] ?User $user): JsonResponse
    {
        if ($user === null) {
            return new JsonResponse(['message' => 'Unauthorized.'], JsonResponse::HTTP_UNAUTHORIZED);
        }

        return new JsonResponse([
            'items' => $this->templateCatalog->all(),
        ]);
    }

    #[Route('/local-issues', name: 'local_task_list', methods: ['GET'])]
    public function localIssues(#[CurrentUser] ?User $user): JsonResponse
    {
        if ($user === null) {
            return new JsonResponse(['message' => 'Unauthorized.'], JsonResponse::HTTP_UNAUTHORIZED);
        }

        return new JsonResponse($this->localTaskService->buildBoard($user));
    }

    #[Route('/local-issues', name: 'local_task_create', methods: ['POST'])]
    public function createLocalIssue(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if ($user === null) {
            return new JsonResponse(['message' => 'Unauthorized.'], JsonResponse::HTTP_UNAUTHORIZED);
        }

        $payload = $this->decodeJson($request);
        if ($payload === null) {
            return new JsonResponse(['message' => 'Invalid JSON payload.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        try {
            return new JsonResponse([
                'item' => $this->localTaskService->createTask($user, $payload),
                'board' => $this->localTaskService->buildBoard($user),
            ], JsonResponse::HTTP_CREATED);
        } catch (\InvalidArgumentException $exception) {
            return new JsonResponse(['message' => $exception->getMessage()], JsonResponse::HTTP_BAD_REQUEST);
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
