<?php

namespace App\Controller;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/auth/config', name: 'auth_config', methods: ['GET'])]
final class AuthConfigController
{
    public function __construct(
        #[Autowire('%env(string:GOOGLE_OAUTH_CLIENT_ID)%')]
        private readonly string $googleClientId,
    ) {
    }

    public function __invoke(): JsonResponse
    {
        return new JsonResponse([
            'googleClientId' => $this->googleClientId,
        ]);
    }
}
