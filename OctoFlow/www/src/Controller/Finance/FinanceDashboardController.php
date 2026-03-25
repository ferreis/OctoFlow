<?php

namespace App\Controller\Finance;

use App\Entity\User;
use App\Finance\FinanceDashboardService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[AsController]
#[IsGranted('IS_AUTHENTICATED_FULLY')]
#[Route('/finance/dashboard')]
final class FinanceDashboardController
{
    use FinanceControllerHelper;

    public function __construct(
        private readonly FinanceDashboardService $financeDashboardService,
    ) {
    }

    #[Route('/summary', name: 'finance_dashboard_summary', methods: ['GET'])]
    public function summary(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if ($user === null) {
            return $this->buildUnauthorizedResponse();
        }

        try {
            return new JsonResponse([
                'item' => $this->financeDashboardService->summary($user, $this->queryArray($request)),
            ]);
        } catch (\Throwable $throwable) {
            return new JsonResponse(['message' => $throwable->getMessage()], $this->resolveExceptionStatus($throwable));
        }
    }

    #[Route('/cashflow', name: 'finance_dashboard_cashflow', methods: ['GET'])]
    public function cashflow(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if ($user === null) {
            return $this->buildUnauthorizedResponse();
        }

        try {
            return new JsonResponse($this->financeDashboardService->cashflow($user, $this->queryArray($request)));
        } catch (\Throwable $throwable) {
            return new JsonResponse(['message' => $throwable->getMessage()], $this->resolveExceptionStatus($throwable));
        }
    }

    #[Route('/categories', name: 'finance_dashboard_categories', methods: ['GET'])]
    public function categories(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if ($user === null) {
            return $this->buildUnauthorizedResponse();
        }

        try {
            return new JsonResponse($this->financeDashboardService->categories($user, $this->queryArray($request)));
        } catch (\Throwable $throwable) {
            return new JsonResponse(['message' => $throwable->getMessage()], $this->resolveExceptionStatus($throwable));
        }
    }
}
