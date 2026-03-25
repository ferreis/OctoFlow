<?php

namespace App\Controller\Finance;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

trait FinanceControllerHelper
{
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

    private function buildUnauthorizedResponse(): JsonResponse
    {
        return new JsonResponse(['message' => 'Unauthorized.'], JsonResponse::HTTP_UNAUTHORIZED);
    }

    private function buildInvalidJsonResponse(): JsonResponse
    {
        return new JsonResponse(['message' => 'Invalid JSON payload.'], JsonResponse::HTTP_BAD_REQUEST);
    }

    private function resolveExceptionStatus(\Throwable $throwable): int
    {
        $message = mb_strtolower($throwable->getMessage());

        if (str_contains($message, 'not found')) {
            return JsonResponse::HTTP_NOT_FOUND;
        }

        return JsonResponse::HTTP_BAD_REQUEST;
    }

    private function queryArray(Request $request): array
    {
        $query = $request->query->all();

        return is_array($query) ? $query : [];
    }
}
