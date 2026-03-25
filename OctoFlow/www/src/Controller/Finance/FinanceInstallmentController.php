<?php

namespace App\Controller\Finance;

use App\Entity\User;
use App\Finance\FinanceInstallmentService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[AsController]
#[IsGranted('IS_AUTHENTICATED_FULLY')]
#[Route('/finance')]
final class FinanceInstallmentController
{
    use FinanceControllerHelper;

    public function __construct(
        private readonly FinanceInstallmentService $financeInstallmentService,
    ) {
    }

    #[Route('/installment-plans', name: 'finance_installment_plans_list', methods: ['GET'])]
    public function listPlans(#[CurrentUser] ?User $user): JsonResponse
    {
        if ($user === null) {
            return $this->buildUnauthorizedResponse();
        }

        return new JsonResponse($this->financeInstallmentService->listPlans($user));
    }

    #[Route('/installment-plans', name: 'finance_installment_plans_create', methods: ['POST'])]
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
            return new JsonResponse([
                'item' => $this->financeInstallmentService->createPlan($user, $payload),
            ], JsonResponse::HTTP_CREATED);
        } catch (\Throwable $throwable) {
            return new JsonResponse(['message' => $throwable->getMessage()], $this->resolveExceptionStatus($throwable));
        }
    }

    #[Route('/installment-plans/{planId<\d+>}/renegotiate', name: 'finance_installment_plans_renegotiate', methods: ['POST'])]
    public function renegotiatePlan(int $planId, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if ($user === null) {
            return $this->buildUnauthorizedResponse();
        }

        $payload = $this->decodeJson($request);
        if ($payload === null) {
            return $this->buildInvalidJsonResponse();
        }

        try {
            return new JsonResponse($this->financeInstallmentService->renegotiatePlan($user, $planId, $payload));
        } catch (\Throwable $throwable) {
            return new JsonResponse(['message' => $throwable->getMessage()], $this->resolveExceptionStatus($throwable));
        }
    }
}
