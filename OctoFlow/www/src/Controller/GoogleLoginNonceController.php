<?php

namespace App\Controller;

use App\Security\Google\GoogleLoginNonceManager;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/auth/google/nonce', name: 'auth_google_nonce', methods: ['GET'])]
final class GoogleLoginNonceController
{
    public function __construct(
        private readonly GoogleLoginNonceManager $nonceManager,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $response = new JsonResponse($this->nonceManager->issue($request));
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Pragma', 'no-cache');

        return $response;
    }
}
