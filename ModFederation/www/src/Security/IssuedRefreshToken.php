<?php

namespace App\Security;

use App\Entity\User;

final class IssuedRefreshToken
{
    public function __construct(
        public readonly User $user,
        public readonly string $plainToken,
        public readonly \DateTimeImmutable $expiresAt,
    ) {
    }
}
