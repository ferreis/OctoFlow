<?php

namespace App\Controller\Finance;

use App\Entity\User;
use App\Finance\FinancePermissionResolver;
use App\Finance\FinanceTransferService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[AsController]
#[IsGranted('IS_AUTHENTICATED_FULLY')]
#[Route('/finance/migration')]
final class FinanceTransferController
{
    use FinanceControllerHelper;

    public function __construct(
        private readonly FinanceTransferService $financeTransferService,
    ) {
    }

    #[Route('/export', name: 'finance_migration_export', methods: ['GET'])]
    #[IsGranted(FinancePermissionResolver::READ)]
    public function exportSnapshot(#[CurrentUser] ?User $user): JsonResponse
    {
        if ($user === null) {
            return $this->buildUnauthorizedResponse();
        }

        try {
            return new JsonResponse([
                'item' => $this->financeTransferService->exportSnapshot($user),
            ]);
        } catch (\Throwable $throwable) {
            return new JsonResponse(['message' => $throwable->getMessage()], $this->resolveExceptionStatus($throwable));
        }
    }

    #[Route('/import', name: 'finance_migration_import', methods: ['POST'])]
    #[IsGranted(FinancePermissionResolver::WRITE)]
    public function importSnapshot(Request $request, #[CurrentUser] ?User $user): JsonResponse
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
                'item' => $this->financeTransferService->importSnapshot($user, $payload),
            ], JsonResponse::HTTP_CREATED);
        } catch (\Throwable $throwable) {
            return new JsonResponse(['message' => $throwable->getMessage()], $this->resolveExceptionStatus($throwable));
        }
    }
}
