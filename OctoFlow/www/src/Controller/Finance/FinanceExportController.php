<?php

namespace App\Controller\Finance;

use App\Entity\User;
use App\Finance\FinanceExportService;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[AsController]
#[IsGranted('IS_AUTHENTICATED_FULLY')]
#[Route('/finance')]
final class FinanceExportController
{
    use FinanceControllerHelper;

    public function __construct(
        private readonly FinanceExportService $financeExportService,
    ) {
    }

    #[Route('/exports', name: 'finance_exports_list', methods: ['GET'])]
    public function listExports(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if ($user === null) {
            return $this->buildUnauthorizedResponse();
        }

        return new JsonResponse($this->financeExportService->listJobs($user, $this->queryArray($request)));
    }

    #[Route('/exports', name: 'finance_exports_create', methods: ['POST'])]
    public function createExport(Request $request, #[CurrentUser] ?User $user): JsonResponse
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
                'item' => $this->financeExportService->createJob($user, $payload),
            ], JsonResponse::HTTP_CREATED);
        } catch (\Throwable $throwable) {
            return new JsonResponse(['message' => $throwable->getMessage()], $this->resolveExceptionStatus($throwable));
        }
    }

    #[Route('/exports/{exportJobId<\d+>}/download', name: 'finance_exports_download', methods: ['GET'])]
    public function downloadExport(int $exportJobId, #[CurrentUser] ?User $user): BinaryFileResponse|JsonResponse
    {
        if ($user === null) {
            return $this->buildUnauthorizedResponse();
        }

        try {
            $filePath = $this->financeExportService->getDownloadableFilePath($user, $exportJobId);

            $response = new BinaryFileResponse($filePath);
            $response->setContentDisposition(
                ResponseHeaderBag::DISPOSITION_ATTACHMENT,
                basename($filePath),
            );

            return $response;
        } catch (\Throwable $throwable) {
            return new JsonResponse(['message' => $throwable->getMessage()], $this->resolveExceptionStatus($throwable));
        }
    }

    #[Route('/exports/{exportJobId<\d+>}', name: 'finance_exports_delete', methods: ['DELETE'])]
    public function deleteExport(int $exportJobId, #[CurrentUser] ?User $user): JsonResponse
    {
        if ($user === null) {
            return $this->buildUnauthorizedResponse();
        }

        try {
            return new JsonResponse($this->financeExportService->deleteJob($user, $exportJobId));
        } catch (\Throwable $throwable) {
            return new JsonResponse(['message' => $throwable->getMessage()], $this->resolveExceptionStatus($throwable));
        }
    }
}
