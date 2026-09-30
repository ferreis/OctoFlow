<?php

namespace App\Security;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\User\UserInterface;

interface AccessTokenManagerInterface
{
    public function issueForUser(UserInterface $user, ?string $previousAccessToken = null): string;

    public function issueForUserFromRequest(UserInterface $user, Request $request): string;

    public function blacklistFromRequest(Request $request, ?UserInterface $expectedUser = null): bool;

    public function blacklistToken(string $accessToken, ?UserInterface $expectedUser = null): bool;

    public function deactivateByHash(string $accessTokenHash): bool;

    public function validateToken(string $accessToken): bool;
}
