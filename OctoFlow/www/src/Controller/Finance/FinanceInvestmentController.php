<?php

namespace App\Controller\Finance;

use App\Entity\User;
use App\Finance\FinanceInvestmentService;
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
final class FinanceInvestmentController
{
    use FinanceControllerHelper;

    public function __construct(
        private readonly FinanceInvestmentService $financeInvestmentService,
    ) {
    }

    #[Route('/investment/simulations', name: 'finance_investment_simulations_create', methods: ['POST'])]
    #[IsGranted(FinancePermissionResolver::WRITE)]
    public function createSimulation(Request $request, #[CurrentUser] ?User $user): JsonResponse
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
                'item' => $this->financeInvestmentService->createSimulation($user, $payload),
            ], JsonResponse::HTTP_CREATED);
        } catch (\Throwable $throwable) {
            return new JsonResponse(['message' => $throwable->getMessage()], $this->resolveExceptionStatus($throwable));
        }
    }

    #[Route('/investment/simulations/{simulationId<\d+>}', name: 'finance_investment_simulations_show', methods: ['GET'])]
    #[IsGranted(FinancePermissionResolver::READ)]
    public function showSimulation(int $simulationId, #[CurrentUser] ?User $user): JsonResponse
    {
        if ($user === null) {
            return $this->buildUnauthorizedResponse();
        }

        try {
            return new JsonResponse([
                'item' => $this->financeInvestmentService->getSimulation($user, $simulationId),
            ]);
        } catch (\Throwable $throwable) {
            return new JsonResponse(['message' => $throwable->getMessage()], $this->resolveExceptionStatus($throwable));
        }
    }

    #[Route('/investment/simulations/{simulationId<\d+>}/convert-to-plan', name: 'finance_investment_simulations_convert', methods: ['POST'])]
    #[IsGranted(FinancePermissionResolver::WRITE)]
    public function convertSimulationToPlan(int $simulationId, Request $request, #[CurrentUser] ?User $user): JsonResponse
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
                'item' => $this->financeInvestmentService->convertSimulationToPlan($user, $simulationId, $payload),
            ], JsonResponse::HTTP_CREATED);
        } catch (\Throwable $throwable) {
            return new JsonResponse(['message' => $throwable->getMessage()], $this->resolveExceptionStatus($throwable));
        }
    }

    #[Route('/investment/plans', name: 'finance_investment_plans_list', methods: ['GET'])]
    #[IsGranted(FinancePermissionResolver::READ)]
    public function listPlans(#[CurrentUser] ?User $user): JsonResponse
    {
        if ($user === null) {
            return $this->buildUnauthorizedResponse();
        }

        return new JsonResponse($this->financeInvestmentService->listPlans($user));
    }

    #[Route('/investment/plans', name: 'finance_investment_plans_create', methods: ['POST'])]
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
            return new JsonResponse([
                'item' => $this->financeInvestmentService->createPlan($user, $payload),
            ], JsonResponse::HTTP_CREATED);
        } catch (\Throwable $throwable) {
            return new JsonResponse(['message' => $throwable->getMessage()], $this->resolveExceptionStatus($throwable));
        }
    }

    #[Route('/investment/plans/{planId<\d+>}', name: 'finance_investment_plans_update', methods: ['PATCH'])]
    #[IsGranted(FinancePermissionResolver::WRITE)]
    public function updatePlan(int $planId, Request $request, #[CurrentUser] ?User $user): JsonResponse
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
                'item' => $this->financeInvestmentService->updatePlan($user, $planId, $payload),
            ]);
        } catch (\Throwable $throwable) {
            return new JsonResponse(['message' => $throwable->getMessage()], $this->resolveExceptionStatus($throwable));
        }
    }
}
