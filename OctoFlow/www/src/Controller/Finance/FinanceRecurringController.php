<?php

namespace App\Controller\Finance;

use App\Entity\User;
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
final class FinanceRecurringController
{
    use FinanceControllerHelper;

    public function __construct(
        private readonly FinanceRecurringService $financeRecurringService,
    ) {
    }

    #[Route('/recurring-rules', name: 'finance_recurring_rules_list', methods: ['GET'])]
    public function listRules(#[CurrentUser] ?User $user): JsonResponse
    {
        if ($user === null) {
            return $this->buildUnauthorizedResponse();
        }

        return new JsonResponse($this->financeRecurringService->listRules($user));
    }

    #[Route('/recurring-rules', name: 'finance_recurring_rules_create', methods: ['POST'])]
    public function createRule(Request $request, #[CurrentUser] ?User $user): JsonResponse
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
                'item' => $this->financeRecurringService->createRule($user, $payload),
            ], JsonResponse::HTTP_CREATED);
        } catch (\Throwable $throwable) {
            return new JsonResponse(['message' => $throwable->getMessage()], $this->resolveExceptionStatus($throwable));
        }
    }

    #[Route('/recurring-rules/{ruleId<\d+>}', name: 'finance_recurring_rules_update', methods: ['PATCH'])]
    public function updateRule(int $ruleId, Request $request, #[CurrentUser] ?User $user): JsonResponse
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
                'item' => $this->financeRecurringService->updateRule($user, $ruleId, $payload),
            ]);
        } catch (\Throwable $throwable) {
            return new JsonResponse(['message' => $throwable->getMessage()], $this->resolveExceptionStatus($throwable));
        }
    }

    #[Route('/recurring-rules/{ruleId<\d+>}', name: 'finance_recurring_rules_delete', methods: ['DELETE'])]
    public function deleteRule(int $ruleId, #[CurrentUser] ?User $user): JsonResponse
    {
        if ($user === null) {
            return $this->buildUnauthorizedResponse();
        }

        try {
            return new JsonResponse([
                'item' => $this->financeRecurringService->deleteRule($user, $ruleId),
            ]);
        } catch (\Throwable $throwable) {
            return new JsonResponse(['message' => $throwable->getMessage()], $this->resolveExceptionStatus($throwable));
        }
    }

    #[Route('/recurring-rules/{ruleId<\d+>}/generate-manual', name: 'finance_recurring_rules_generate_manual', methods: ['POST'])]
    public function generateRuleManually(int $ruleId, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if ($user === null) {
            return $this->buildUnauthorizedResponse();
        }

        $payload = [];
        if (trim($request->getContent()) !== '') {
            $payload = $this->decodeJson($request);
            if ($payload === null) {
                return $this->buildInvalidJsonResponse();
            }
        }

        try {
            return new JsonResponse([
                'item' => $this->financeRecurringService->generateRuleEntryManually($user, $ruleId, $payload),
            ]);
        } catch (\Throwable $throwable) {
            return new JsonResponse(['message' => $throwable->getMessage()], $this->resolveExceptionStatus($throwable));
        }
    }

}
