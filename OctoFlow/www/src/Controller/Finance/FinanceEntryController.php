<?php

namespace App\Controller\Finance;

use App\Entity\User;
use App\Finance\FinanceEntryService;
use App\Finance\FinanceInput;
use App\Finance\FinanceRecurringService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[AsController]
#[IsGranted('IS_AUTHENTICATED_FULLY')]
#[Route('/finance')]
final class FinanceEntryController
{
    use FinanceControllerHelper;

    public function __construct(
        private readonly FinanceEntryService $financeEntryService,
        private readonly FinanceRecurringService $financeRecurringService,
    ) {
    }

    #[Route('/entries', name: 'finance_entries_list', methods: ['GET'])]
    public function listEntries(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if ($user === null) {
            return $this->buildUnauthorizedResponse();
        }

        $filters = $this->queryArray($request);

        $startDate = FinanceInput::normalizeOptionalDate($filters['startDate'] ?? null);
        $endDate = FinanceInput::normalizeOptionalDate($filters['endDate'] ?? null);

        if ($startDate instanceof \DateTimeImmutable && $endDate instanceof \DateTimeImmutable && $endDate >= $startDate) {
            try {
                $this->financeRecurringService->generateMissingEntriesForRange($user, $startDate, $endDate, 'lazy');
            } catch (\Throwable) {
                // A listagem segue mesmo se a geração lazy falhar.
            }
        }

        return new JsonResponse($this->financeEntryService->listEntries($user, $filters));
    }

    #[Route('/entries/{entryId<\d+>}', name: 'finance_entries_show', methods: ['GET'])]
    public function showEntry(int $entryId, #[CurrentUser] ?User $user): JsonResponse
    {
        if ($user === null) {
            return $this->buildUnauthorizedResponse();
        }

        try {
            return new JsonResponse([
                'item' => $this->financeEntryService->getEntryById((int) $user->getId(), $entryId),
            ]);
        } catch (\Throwable $throwable) {
            return new JsonResponse(['message' => $throwable->getMessage()], $this->resolveExceptionStatus($throwable));
        }
    }

    #[Route('/entries', name: 'finance_entries_create', methods: ['POST'])]
    public function createEntry(Request $request, #[CurrentUser] ?User $user): JsonResponse
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
                'item' => $this->financeEntryService->createEntry($user, $payload),
            ], JsonResponse::HTTP_CREATED);
        } catch (\Throwable $throwable) {
            return new JsonResponse(['message' => $throwable->getMessage()], $this->resolveExceptionStatus($throwable));
        }
    }

    #[Route('/entries/{entryId<\d+>}', name: 'finance_entries_update', methods: ['PATCH'])]
    public function updateEntry(int $entryId, Request $request, #[CurrentUser] ?User $user): JsonResponse
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
                'item' => $this->financeEntryService->updateEntry($user, $entryId, $payload),
            ]);
        } catch (\Throwable $throwable) {
            return new JsonResponse(['message' => $throwable->getMessage()], $this->resolveExceptionStatus($throwable));
        }
    }

    #[Route('/entries/{entryId<\d+>}', name: 'finance_entries_delete', methods: ['DELETE'])]
    public function deleteEntry(int $entryId, #[CurrentUser] ?User $user): JsonResponse
    {
        if ($user === null) {
            return $this->buildUnauthorizedResponse();
        }

        try {
            return new JsonResponse([
                'item' => $this->financeEntryService->softDeleteEntry($user, $entryId),
            ]);
        } catch (\Throwable $throwable) {
            return new JsonResponse(['message' => $throwable->getMessage()], $this->resolveExceptionStatus($throwable));
        }
    }

    #[Route('/entries/{entryId<\d+>}/settlements', name: 'finance_entries_settlements_create', methods: ['POST'])]
    public function createSettlement(int $entryId, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if ($user === null) {
            return $this->buildUnauthorizedResponse();
        }

        $payload = $this->decodeJson($request);
        if ($payload === null) {
            return $this->buildInvalidJsonResponse();
        }

        try {
            return new JsonResponse($this->financeEntryService->settleEntry($user, $entryId, $payload), JsonResponse::HTTP_CREATED);
        } catch (\Throwable $throwable) {
            return new JsonResponse(['message' => $throwable->getMessage()], $this->resolveExceptionStatus($throwable));
        }
    }

    #[Route('/entries/{entryId<\d+>}/settlements/{settlementId<\d+>}', name: 'finance_entries_settlements_delete', methods: ['DELETE'])]
    public function deleteSettlement(int $entryId, int $settlementId, #[CurrentUser] ?User $user): JsonResponse
    {
        if ($user === null) {
            return $this->buildUnauthorizedResponse();
        }

        try {
            return new JsonResponse([
                'item' => $this->financeEntryService->deleteSettlement($user, $entryId, $settlementId),
            ]);
        } catch (\Throwable $throwable) {
            return new JsonResponse(['message' => $throwable->getMessage()], $this->resolveExceptionStatus($throwable));
        }
    }
}
