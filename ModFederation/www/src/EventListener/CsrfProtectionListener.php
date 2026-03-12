<?php

namespace App\EventListener;

use App\Security\CsrfTokenManager;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

#[AsEventListener(event: KernelEvents::REQUEST, priority: 25)]
final class CsrfProtectionListener
{
    public function __construct(
        private readonly CsrfTokenManager $csrfTokenManager,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        if ($request->isMethodSafe()) {
            return;
        }

        $path = $request->getPathInfo();

        if ($this->isExemptPath($path) || !$this->isProtectedPath($path)) {
            return;
        }

        if ($this->csrfTokenManager->isValidRequest($request)) {
            return;
        }

        $event->setResponse(new JsonResponse([
            'message' => 'Invalid or missing CSRF token.',
            'csrf' => [
                'header' => $this->csrfTokenManager->getHeaderName(),
                'actionHeader' => $this->csrfTokenManager->getActionHeaderName(),
                'mode' => 'action-scoped-one-time-token',
            ],
        ], JsonResponse::HTTP_FORBIDDEN));
    }

    private function isProtectedPath(string $path): bool
    {
        return preg_match('#^/(auth|tasks|api)(?:/|$)#', $path) === 1;
    }

    private function isExemptPath(string $path): bool
    {
        return preg_match('#^/(auth/csrf/challenge|csrf/challenge)(?:/|$)#', $path) === 1;
    }
}
