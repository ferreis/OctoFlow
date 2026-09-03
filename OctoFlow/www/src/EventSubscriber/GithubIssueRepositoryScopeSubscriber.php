<?php

namespace App\EventSubscriber;

use App\Entity\User;
use App\Github\Exception\GithubActionForbiddenException;
use App\Github\GithubIssueRepositoryScopeGuard;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

final class GithubIssueRepositoryScopeSubscriber implements EventSubscriberInterface
{
    private const PROTECTED_ROUTES = [
        'github_issue_show',
        'github_issue_update',
        'github_issue_sub_issue_create',
    ];

    public function __construct(
        private readonly TokenStorageInterface $tokenStorage,
        private readonly GithubIssueRepositoryScopeGuard $repositoryScopeGuard,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::CONTROLLER => 'onKernelController'];
    }

    public function onKernelController(ControllerEvent $event): void
    {
        $request = $event->getRequest();
        $route = trim((string) $request->attributes->get('_route', ''));
        if (!in_array($route, self::PROTECTED_ROUTES, true)) {
            return;
        }

        $user = $this->tokenStorage->getToken()?->getUser();
        if (!$user instanceof User) {
            return;
        }

        $issueId = trim((string) $request->attributes->get('issueId', ''));

        try {
            $this->repositoryScopeGuard->assertIssueAllowed($user, $issueId);
        } catch (GithubActionForbiddenException $exception) {
            throw new AccessDeniedHttpException($exception->getMessage(), $exception);
        }
    }
}
