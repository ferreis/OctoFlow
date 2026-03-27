<?php

namespace App\Controller;

use App\Entity\User;
use App\Github\GithubIssueTemplateCatalog;
use App\Github\GithubIssueUpdateTemplateCatalog;
use App\Github\TemplateAccessService;
use App\Task\LocalTaskService;
use Symfony\Component\HttpFoundation\File\UploadedFile;
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
        private readonly GithubIssueTemplateCatalog $createTemplateCatalog,
        private readonly GithubIssueUpdateTemplateCatalog $updateTemplateCatalog,
        private readonly TemplateAccessService $templateAccessService,
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
            'items' => $this->templateAccessService->filterVisibleTemplates($user, $this->createTemplateCatalog->all()),
        ]);
    }

    #[Route('/update-templates', name: 'task_update_template_list', methods: ['GET'])]
    public function updateTemplates(#[CurrentUser] ?User $user): JsonResponse
    {
        if ($user === null) {
            return new JsonResponse(['message' => 'Unauthorized.'], JsonResponse::HTTP_UNAUTHORIZED);
        }

        return new JsonResponse([
            'items' => $this->templateAccessService->filterVisibleTemplates($user, $this->updateTemplateCatalog->all()),
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

    #[Route('/local-issues/{taskId<\d+>}', name: 'local_task_show', methods: ['GET'])]
    public function showLocalIssue(int $taskId, #[CurrentUser] ?User $user): JsonResponse
    {
        if ($user === null) {
            return new JsonResponse(['message' => 'Unauthorized.'], JsonResponse::HTTP_UNAUTHORIZED);
        }

        try {
            return new JsonResponse([
                'item' => $this->localTaskService->getTask($user, $taskId),
            ]);
        } catch (\InvalidArgumentException $exception) {
            return new JsonResponse(['message' => $exception->getMessage()], JsonResponse::HTTP_NOT_FOUND);
        }
    }

    #[Route('/local-issues/{taskId<\d+>}', name: 'local_task_update', methods: ['PATCH'])]
    public function updateLocalIssue(int $taskId, Request $request, #[CurrentUser] ?User $user): JsonResponse
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
                'item' => $this->localTaskService->updateTask($user, $taskId, $payload),
                'board' => $this->localTaskService->buildBoard($user),
            ]);
        } catch (\InvalidArgumentException $exception) {
            $status = $exception->getMessage() === 'Local task not found.'
                ? JsonResponse::HTTP_NOT_FOUND
                : JsonResponse::HTTP_BAD_REQUEST;

            return new JsonResponse(['message' => $exception->getMessage()], $status);
        }
    }

    #[Route('/local-issues/{taskId<\d+>}/sync', name: 'local_task_sync', methods: ['POST'])]
    public function syncLocalIssue(int $taskId, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if ($user === null) {
            return new JsonResponse(['message' => 'Unauthorized.'], JsonResponse::HTTP_UNAUTHORIZED);
        }

        $payload = $this->decodeJson($request);
        if ($payload === null) {
            return new JsonResponse(['message' => 'Invalid JSON payload.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        try {
            $result = $this->localTaskService->syncTaskToGithub($user, $taskId, $payload);

            return new JsonResponse([
                'item' => $result['item'],
                'github' => $result['github'],
                'board' => $this->localTaskService->buildBoard($user),
            ]);
        } catch (\InvalidArgumentException $exception) {
            $status = $exception->getMessage() === 'Local task not found.'
                ? JsonResponse::HTTP_NOT_FOUND
                : JsonResponse::HTTP_BAD_REQUEST;

            return new JsonResponse(['message' => $exception->getMessage()], $status);
        } catch (\Throwable $exception) {
            return new JsonResponse(['message' => $exception->getMessage()], JsonResponse::HTTP_BAD_REQUEST);
        }
    }

    #[Route('/test/post-capture', name: 'task_test_post_capture', methods: ['POST'])]
    public function testPostCapture(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        // dd($request);
        // die;
        if ($user === null) {
            return new JsonResponse(['message' => 'Unauthorized.'], JsonResponse::HTTP_UNAUTHORIZED);
        }

        $rawBody = (string) $request->getContent();
        $decodedJsonPayload = null;
        $jsonDecodeError = null;

        if ($rawBody !== '') {
            try {
                /** @var array<string, mixed>|list<mixed>|scalar|null $decodedPayload */
                $decodedPayload = json_decode($rawBody, true, flags: \JSON_THROW_ON_ERROR);
                $decodedJsonPayload = $decodedPayload;
            } catch (\JsonException $exception) {
                $jsonDecodeError = $exception->getMessage();
            }
        }

        $tabIdentifier = trim((string) $request->headers->get('x-tab-id', ''));
        $browserIdentifier = trim((string) $request->headers->get('x-browser-id', ''));
        $browserFingerprintHeader = trim((string) $request->headers->get('x-browser-fingerprint', ''));
        $userAgent = trim((string) $request->headers->get('user-agent', ''));
        $acceptLanguage = trim((string) $request->headers->get('accept-language', ''));
        $remoteAddress = trim((string) ($request->getClientIp() ?? ''));
        $identityContextHash = hash(
            'sha256',
            implode('|', [
                $browserIdentifier,
                $tabIdentifier,
                $userAgent,
                $acceptLanguage,
                $remoteAddress,
            ]),
        );

        return new JsonResponse([
            'message' => 'POST capturado com sucesso.',
            'received' => [
                'method' => $request->getMethod(),
                'path' => $request->getPathInfo(),
                'query' => $request->query->all(),
                'headers' => $request->headers->all(),
                'contentType' => (string) $request->headers->get('content-type', ''),
                'rawBody' => $rawBody,
                'jsonPayload' => $decodedJsonPayload,
                'jsonDecodeError' => $jsonDecodeError,
                'formPayload' => $request->request->all(),
                'uploadedFiles' => $this->normalizeUploadedFiles($request->files->all()),
                'clientIdentity' => [
                    'tabId' => $tabIdentifier,
                    'browserId' => $browserIdentifier,
                    'browserFingerprint' => $browserFingerprintHeader,
                    'userAgent' => $userAgent,
                    'acceptLanguage' => $acceptLanguage,
                    'remoteAddress' => $remoteAddress,
                    'identityContextHash' => $identityContextHash,
                ],
            ],
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

    /**
     * @param array<string, mixed> $uploadedFiles
     *
     * @return array<string, mixed>
     */
    private function normalizeUploadedFiles(array $uploadedFiles): array
    {
        $normalizedFiles = [];

        foreach ($uploadedFiles as $fieldName => $uploadedFile) {
            if ($uploadedFile instanceof UploadedFile) {
                $normalizedFiles[$fieldName] = [
                    'originalName' => $uploadedFile->getClientOriginalName(),
                    'mimeType' => $uploadedFile->getClientMimeType(),
                    'sizeBytes' => $uploadedFile->getSize(),
                ];
                continue;
            }

            if (is_array($uploadedFile)) {
                $normalizedFiles[$fieldName] = $this->normalizeUploadedFiles($uploadedFile);
            }
        }

        return $normalizedFiles;
    }
}
