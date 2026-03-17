<?php

namespace App\Github;

final class GithubRuntimeConfiguration
{
    public function __construct(
        public readonly string $token,
        public readonly string $repositoryOwner,
        public readonly string $repositoryName,
    ) {
    }
}
