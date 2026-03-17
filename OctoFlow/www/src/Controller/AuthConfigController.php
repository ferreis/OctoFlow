<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/auth/config', name: 'auth_config', methods: ['GET'])]
class AuthConfigController extends AbstractController
{
    public function __invoke(): JsonResponse
    {
        // Retorna apenas configurações públicas (sem dados sensíveis)
        return $this->json([
            'googleClientId' => $_ENV['GOOGLE_OAUTH_CLIENT_ID'] ?? '',
        ]);
    }
}
