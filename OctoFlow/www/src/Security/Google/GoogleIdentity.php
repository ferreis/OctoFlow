<?php

namespace App\Security\Google;

final readonly class GoogleIdentity
{
    public function __construct(
        public string $subject,
        public string $email,
        public bool $emailVerified,
        public ?string $name = null,
        public ?string $picture = null,
        public ?string $hostedDomain = null,
    ) {
    }
}
