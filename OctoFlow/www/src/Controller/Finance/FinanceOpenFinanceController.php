<?php

namespace App\Controller\Finance;

use App\Entity\User;
use App\Finance\FinanceOpenFinanceService;
use App\Finance\FinancePermissionResolver;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[AsController]
#[IsGranted('IS_AUTHENTICATED_FULLY')]
#[Route('/finance/open-finance')]
final class FinanceOpenFinanceController
{
    use FinanceControllerHelper;

    public function __construct(
        private readonly FinanceOpenFinanceService $financeOpenFinanceService,
    ) {
    }

    #[Route('/providers', name: 'finance_open_finance_providers_list', methods: ['GET'])]
    #[IsGranted(FinancePermissionResolver::READ)]
    public function listProviders(): JsonResponse
    {
        return new JsonResponse($this->financeOpenFinanceService->listProviders());
    }

    #[Route('/connections', name: 'finance_open_finance_connections_list', methods: ['GET'])]
    #[IsGranted(FinancePermissionResolver::READ)]
    public function listConnections(#[CurrentUser] ?User $user): JsonResponse
    {
        if ($user === null) {
            return $this->buildUnauthorizedResponse();
        }

        return new JsonResponse($this->financeOpenFinanceService->listConnections($user));
    }

    #[Route('/connections', name: 'finance_open_finance_connections_create', methods: ['POST'])]
    #[IsGranted(FinancePermissionResolver::WRITE)]
    public function createConnection(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if ($user === null) {
            return $this->buildUnauthorizedResponse();
        }

        $payload = $this->decodeJson($request);
        if ($payload === null) {
            return $this->buildInvalidJsonResponse();
        }

        try {
            return new JsonResponse([
                'item' => $this->financeOpenFinanceService->createConnection($user, $payload),
            ], JsonResponse::HTTP_CREATED);
        } catch (\Throwable $throwable) {
            return new JsonResponse(['message' => $throwable->getMessage()], $this->resolveExceptionStatus($throwable));
        }
    }

    #[Route('/connections/{connectionId<\d+>}/sync', name: 'finance_open_finance_connections_sync', methods: ['POST'])]
    #[IsGranted(FinancePermissionResolver::WRITE)]
    public function syncConnection(int $connectionId, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if ($user === null) {
            return $this->buildUnauthorizedResponse();
        }

        $payload = $this->decodeJson($request);
        if ($payload === null) {
            return $this->buildInvalidJsonResponse();
        }

        try {
            return new JsonResponse($this->financeOpenFinanceService->syncConnection($user, $connectionId, $payload));
        } catch (\Throwable $throwable) {
            return new JsonResponse(['message' => $throwable->getMessage()], $this->resolveExceptionStatus($throwable));
        }
    }

    #[Route('/connections/{connectionId<\d+>}', name: 'finance_open_finance_connections_delete', methods: ['DELETE'])]
    #[IsGranted(FinancePermissionResolver::WRITE)]
    public function deleteConnection(int $connectionId, #[CurrentUser] ?User $user): JsonResponse
    {
        if ($user === null) {
            return $this->buildUnauthorizedResponse();
        }

        try {
            return new JsonResponse($this->financeOpenFinanceService->deleteConnection($user, $connectionId));
        } catch (\Throwable $throwable) {
            return new JsonResponse(['message' => $throwable->getMessage()], $this->resolveExceptionStatus($throwable));
        }
    }
}
