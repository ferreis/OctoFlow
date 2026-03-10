<?php

namespace App\Controller;

use App\Entity\Task;
use App\Entity\User;
use App\Repository\TaskRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[AsController]
#[IsGranted('IS_AUTHENTICATED_FULLY')]
#[Route('/tasks')]
class TaskController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TaskRepository $taskRepository,
    ) {
    }

    #[Route('', name: 'task_list', methods: ['GET'])]
    public function list(#[CurrentUser] ?User $user): JsonResponse
    {
        if ($user === null) {
            return new JsonResponse(['message' => 'Unauthorized.'], JsonResponse::HTTP_UNAUTHORIZED);
        }

        $tasks = $this->taskRepository->findAllByOwner($user);

        return new JsonResponse([
            'items' => array_map(fn (Task $task): array => $this->normalizeTask($task), $tasks),
        ]);
    }

    #[Route('', name: 'task_create', methods: ['POST'])]
    public function create(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if ($user === null) {
            return new JsonResponse(['message' => 'Unauthorized.'], JsonResponse::HTTP_UNAUTHORIZED);
        }

        $payload = $this->decodeJson($request);
        if ($payload === null) {
            return new JsonResponse(['message' => 'Invalid JSON payload.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $title = trim((string) ($payload['title'] ?? ''));
        if ($title === '') {
            return new JsonResponse(['message' => 'The field "title" is required.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $task = (new Task())
            ->setOwner($user)
            ->setTitle($title)
            ->setDescription($this->optionalString($payload['description'] ?? null))
            ->setCompleted((bool) ($payload['completed'] ?? false));

        $this->entityManager->persist($task);
        $this->entityManager->flush();

        return new JsonResponse(['item' => $this->normalizeTask($task)], JsonResponse::HTTP_CREATED);
    }

    #[Route('/{id<\d+>}', name: 'task_show', methods: ['GET'])]
    public function show(int $id, #[CurrentUser] ?User $user): JsonResponse
    {
        if ($user === null) {
            return new JsonResponse(['message' => 'Unauthorized.'], JsonResponse::HTTP_UNAUTHORIZED);
        }

        $task = $this->taskRepository->findOneOwnedBy($id, $user);
        if ($task === null) {
            return new JsonResponse(['message' => 'Task not found.'], JsonResponse::HTTP_NOT_FOUND);
        }

        return new JsonResponse(['item' => $this->normalizeTask($task)]);
    }

    #[Route('/{id<\d+>}', name: 'task_update', methods: ['PUT', 'PATCH'])]
    public function update(int $id, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if ($user === null) {
            return new JsonResponse(['message' => 'Unauthorized.'], JsonResponse::HTTP_UNAUTHORIZED);
        }

        $task = $this->taskRepository->findOneOwnedBy($id, $user);
        if ($task === null) {
            return new JsonResponse(['message' => 'Task not found.'], JsonResponse::HTTP_NOT_FOUND);
        }

        $payload = $this->decodeJson($request);
        if ($payload === null) {
            return new JsonResponse(['message' => 'Invalid JSON payload.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        if (array_key_exists('title', $payload)) {
            $title = trim((string) $payload['title']);
            if ($title === '') {
                return new JsonResponse(['message' => 'The field "title" cannot be blank.'], JsonResponse::HTTP_BAD_REQUEST);
            }

            $task->setTitle($title);
        }

        if (array_key_exists('description', $payload)) {
            $task->setDescription($this->optionalString($payload['description']));
        }

        if (array_key_exists('completed', $payload)) {
            $task->setCompleted((bool) $payload['completed']);
        }

        $task->setUpdatedAt(new \DateTimeImmutable());
        $this->entityManager->flush();

        return new JsonResponse(['item' => $this->normalizeTask($task)]);
    }

    #[Route('/{id<\d+>}', name: 'task_delete', methods: ['DELETE'])]
    public function delete(int $id, #[CurrentUser] ?User $user): JsonResponse
    {
        if ($user === null) {
            return new JsonResponse(['message' => 'Unauthorized.'], JsonResponse::HTTP_UNAUTHORIZED);
        }

        $task = $this->taskRepository->findOneOwnedBy($id, $user);
        if ($task === null) {
            return new JsonResponse(['message' => 'Task not found.'], JsonResponse::HTTP_NOT_FOUND);
        }

        $this->entityManager->remove($task);
        $this->entityManager->flush();

        return new JsonResponse(null, JsonResponse::HTTP_NO_CONTENT);
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
     * @return array<string, mixed>
     */
    private function normalizeTask(Task $task): array
    {
        return [
            'id' => $task->getId(),
            'title' => $task->getTitle(),
            'description' => $task->getDescription(),
            'completed' => $task->isCompleted(),
            'createdAt' => $task->getCreatedAt()->format(DATE_ATOM),
            'updatedAt' => $task->getUpdatedAt()->format(DATE_ATOM),
            'owner' => [
                'id' => $task->getOwner()?->getId(),
                'email' => $task->getOwner()?->getEmail(),
            ],
        ];
    }

    private function optionalString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $stringValue = trim((string) $value);

        return $stringValue === '' ? null : $stringValue;
    }
}
