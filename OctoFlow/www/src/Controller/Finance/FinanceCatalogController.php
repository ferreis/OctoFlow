<?php

namespace App\Controller\Finance;

use App\Entity\User;
use App\Finance\FinanceCatalogService;
use App\Finance\FinanceInput;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[AsController]
#[IsGranted('IS_AUTHENTICATED_FULLY')]
#[Route('/finance')]
final class FinanceCatalogController
{
    use FinanceControllerHelper;

    public function __construct(
        private readonly FinanceCatalogService $financeCatalogService,
    ) {
    }

    #[Route('/categories', name: 'finance_categories_list', methods: ['GET'])]
    public function listCategories(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if ($user === null) {
            return $this->buildUnauthorizedResponse();
        }

        return new JsonResponse($this->financeCatalogService->listCategories($user, $this->queryArray($request)));
    }

    #[Route('/categories', name: 'finance_categories_create', methods: ['POST'])]
    public function createCategory(Request $request, #[CurrentUser] ?User $user): JsonResponse
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
                'item' => $this->financeCatalogService->createCategory($user, $payload),
            ], JsonResponse::HTTP_CREATED);
        } catch (\Throwable $throwable) {
            return new JsonResponse(['message' => $throwable->getMessage()], $this->resolveExceptionStatus($throwable));
        }
    }

    #[Route('/categories/{categoryId<\d+>}', name: 'finance_categories_update', methods: ['PATCH'])]
    public function updateCategory(int $categoryId, Request $request, #[CurrentUser] ?User $user): JsonResponse
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
                'item' => $this->financeCatalogService->updateCategory($user, $categoryId, $payload),
            ]);
        } catch (\Throwable $throwable) {
            return new JsonResponse(['message' => $throwable->getMessage()], $this->resolveExceptionStatus($throwable));
        }
    }

    #[Route('/recurring-types', name: 'finance_recurring_types_list', methods: ['GET'])]
    public function listRecurringTypes(#[CurrentUser] ?User $user): JsonResponse
    {
        if ($user === null) {
            return $this->buildUnauthorizedResponse();
        }

        return new JsonResponse($this->financeCatalogService->listRecurringTypes($user));
    }

    #[Route('/recurring-types', name: 'finance_recurring_types_create', methods: ['POST'])]
    public function createRecurringType(Request $request, #[CurrentUser] ?User $user): JsonResponse
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
                'item' => $this->financeCatalogService->createRecurringType($user, $payload),
            ], JsonResponse::HTTP_CREATED);
        } catch (\Throwable $throwable) {
            return new JsonResponse(['message' => $throwable->getMessage()], $this->resolveExceptionStatus($throwable));
        }
    }

    #[Route('/recurring-types/{recurringTypeId<\d+>}', name: 'finance_recurring_types_update', methods: ['PATCH'])]
    public function updateRecurringType(int $recurringTypeId, Request $request, #[CurrentUser] ?User $user): JsonResponse
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
                'item' => $this->financeCatalogService->updateRecurringType($user, $recurringTypeId, $payload),
            ]);
        } catch (\Throwable $throwable) {
            return new JsonResponse(['message' => $throwable->getMessage()], $this->resolveExceptionStatus($throwable));
        }
    }

    #[Route('/recurring-types/{recurringTypeId<\d+>}', name: 'finance_recurring_types_delete', methods: ['DELETE'])]
    public function deleteRecurringType(int $recurringTypeId, #[CurrentUser] ?User $user): JsonResponse
    {
        if ($user === null) {
            return $this->buildUnauthorizedResponse();
        }

        try {
            return new JsonResponse([
                'item' => $this->financeCatalogService->deleteRecurringType($user, $recurringTypeId),
            ]);
        } catch (\Throwable $throwable) {
            return new JsonResponse(['message' => $throwable->getMessage()], $this->resolveExceptionStatus($throwable));
        }
    }

    #[Route('/bank-accounts', name: 'finance_bank_accounts_list', methods: ['GET'])]
    public function listBankAccounts(#[CurrentUser] ?User $user): JsonResponse
    {
        if ($user === null) {
            return $this->buildUnauthorizedResponse();
        }

        return new JsonResponse($this->financeCatalogService->listBankAccounts($user));
    }

    #[Route('/bank-accounts', name: 'finance_bank_accounts_create', methods: ['POST'])]
    public function createBankAccount(Request $request, #[CurrentUser] ?User $user): JsonResponse
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
                'item' => $this->financeCatalogService->createBankAccount($user, $payload),
            ], JsonResponse::HTTP_CREATED);
        } catch (\Throwable $throwable) {
            return new JsonResponse(['message' => $throwable->getMessage()], $this->resolveExceptionStatus($throwable));
        }
    }

    #[Route('/bank-accounts/{bankAccountId<\d+>}', name: 'finance_bank_accounts_update', methods: ['PATCH'])]
    public function updateBankAccount(int $bankAccountId, Request $request, #[CurrentUser] ?User $user): JsonResponse
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
                'item' => $this->financeCatalogService->updateBankAccount($user, $bankAccountId, $payload),
            ]);
        } catch (\Throwable $throwable) {
            return new JsonResponse(['message' => $throwable->getMessage()], $this->resolveExceptionStatus($throwable));
        }
    }

    #[Route('/bank-accounts/{bankAccountId<\d+>}/status', name: 'finance_bank_accounts_status_update', methods: ['PATCH'])]
    public function updateBankAccountStatus(int $bankAccountId, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if ($user === null) {
            return $this->buildUnauthorizedResponse();
        }

        $payload = $this->decodeJson($request);
        if ($payload === null) {
            return $this->buildInvalidJsonResponse();
        }

        $isActive = array_key_exists('isActive', $payload)
            ? FinanceInput::normalizeBoolean($payload['isActive'], true)
            : true;

        try {
            return new JsonResponse([
                'item' => $this->financeCatalogService->updateBankAccountStatus($user, $bankAccountId, $isActive),
            ]);
        } catch (\Throwable $throwable) {
            return new JsonResponse(['message' => $throwable->getMessage()], $this->resolveExceptionStatus($throwable));
        }
    }
}
