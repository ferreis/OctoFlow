<?php

namespace App\Controller\Finance;

use App\Entity\User;
use App\Finance\FinanceCurrencyService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[AsController]
#[IsGranted('IS_AUTHENTICATED_FULLY')]
#[Route('/finance/currencies')]
final class FinanceCurrencyController
{
    use FinanceControllerHelper;

    public function __construct(
        private readonly FinanceCurrencyService $financeCurrencyService,
    ) {
    }

    #[Route('', name: 'finance_currencies_list', methods: ['GET'])]
    public function listCurrencies(#[CurrentUser] ?User $user): JsonResponse
    {
        if ($user === null) {
            return $this->buildUnauthorizedResponse();
        }

        try {
            return new JsonResponse($this->financeCurrencyService->listCurrencies());
        } catch (\Throwable $throwable) {
            return new JsonResponse(['message' => $throwable->getMessage()], $this->resolveExceptionStatus($throwable));
        }
    }

    #[Route('/rates', name: 'finance_currencies_rates', methods: ['GET'])]
    public function listRates(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if ($user === null) {
            return $this->buildUnauthorizedResponse();
        }

        try {
            return new JsonResponse($this->financeCurrencyService->rates($this->queryArray($request)));
        } catch (\Throwable $throwable) {
            return new JsonResponse(['message' => $throwable->getMessage()], $this->resolveExceptionStatus($throwable));
        }
    }
}
