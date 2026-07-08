<?php

namespace App\Controller\Finance;

use App\Entity\User;
use App\Finance\FinanceDebtPlanService;
use App\Finance\FinancePermissionResolver;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[AsController]
#[IsGranted('IS_AUTHENTICATED_FULLY')]
#[Route('/finance')]
final class FinanceDebtPlanController
{
    use FinanceControllerHelper;

    public function __construct(
        private readonly FinanceDebtPlanService $financeDebtPlanService,
    ) {
    }

    #[Route('/debt-plans', name: 'finance_debt_plans_list', methods: ['GET'])]
    #[IsGranted(FinancePermissionResolver::READ)]
    public function listPlans(#[CurrentUser] ?User $user): JsonResponse
    {
        if ($user === null) {
            return $this->buildUnauthorizedResponse();
        }

        return new JsonResponse($this->financeDebtPlanService->listPlans($user));
    }

    #[Route('/debt-plans/preview', name: 'finance_debt_plans_preview', methods: ['POST'])]
    #[IsGranted(FinancePermissionResolver::READ)]
    public function previewPlan(Request $request, #[CurrentUser] ?User $user): JsonResponse
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
                'item' => $this->financeDebtPlanService->previewPlan($user, $payload),
            ]);
        } catch (\Throwable $throwable) {
            return new JsonResponse(['message' => $throwable->getMessage()], $this->resolveExceptionStatus($throwable));
        }
    }

    #[Route('/debt-plans', name: 'finance_debt_plans_create', methods: ['POST'])]
    #[IsGranted(FinancePermissionResolver::WRITE)]
    public function createPlan(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if ($user === null) {
            return $this->buildUnauthorizedResponse();
        }

        $payload = $this->decodeJson($request);
        if ($payload === null) {
            return $this->buildInvalidJsonResponse();
        }

        try {
            return new JsonResponse($this->financeDebtPlanService->createPlan($user, $payload), JsonResponse::HTTP_CREATED);
        } catch (\Throwable $throwable) {
            return new JsonResponse(['message' => $throwable->getMessage()], $this->resolveExceptionStatus($throwable));
        }
    }

    #[Route('/debt-plans/{debtPlanId<\d+>}', name: 'finance_debt_plans_delete', methods: ['DELETE'])]
    #[IsGranted(FinancePermissionResolver::WRITE)]
    public function deletePlan(int $debtPlanId, #[CurrentUser] ?User $user): JsonResponse
    {
        if ($user === null) {
            return $this->buildUnauthorizedResponse();
        }

        try {
            return new JsonResponse($this->financeDebtPlanService->deletePlan($user, $debtPlanId));
        } catch (\Throwable $throwable) {
            return new JsonResponse(['message' => $throwable->getMessage()], $this->resolveExceptionStatus($throwable));
        }
    }
}
