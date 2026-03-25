<?php

namespace App\Controller;

use App\Security\CsrfTokenManager;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Annotation\Route;

#[AsController]
final class CsrfChallengeController
{
    public function __construct(
        private readonly CsrfTokenManager $csrfTokenManager,
    ) {
    }

    #[Route('/csrf/challenge', name: 'csrf_challenge', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $payload = $this->decodeJson($request);
        if ($payload === null) {
            return new JsonResponse(['message' => 'Invalid JSON payload.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $method = strtoupper(trim((string) ($payload['method'] ?? '')));
        $path = '/' . ltrim((string) ($payload['path'] ?? ''), '/');
        $actionId = trim((string) ($payload['actionId'] ?? ''));

        if (!$this->isProtectedAction($method, $path)) {
            return new JsonResponse(['message' => 'CSRF challenge is not available for this action.'], JsonResponse::HTTP_FORBIDDEN);
        }

        try {
            return new JsonResponse($this->csrfTokenManager->issueChallenge($request, $method, $path, $actionId));
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

    private function isProtectedAction(string $method, string $path): bool
    {
        if (!in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return false;
        }

        if (preg_match('#^/api(?:/|$)#', $path) === 1) {
            return true;
        }

        if (preg_match('#^/github(?:/|$)#', $path) === 1) {
            return true;
        }

        if (preg_match('#^/tasks(?:/|$)#', $path) === 1) {
            return true;
        }

        if (preg_match('#^/ui(?:/|$)#', $path) === 1) {
            return true;
        }

        if (preg_match('#^/finance(?:/|$)#', $path) === 1) {
            return true;
        }

        if (preg_match('#^/auth/(emails(?:/default)?|google/link|profile/avatar)$#', $path) === 1) {
            return true;
        }

        return false;
    }
}
