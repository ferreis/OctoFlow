<?php

namespace App\Github;

interface GithubGraphQLClientInterface
{
    /**
     * @param non-empty-string $token
     * @param array<string, mixed> $variables
     *
     * @return array<string, mixed>
     */
    public function query(string $token, string $query, array $variables = []): array;
}
