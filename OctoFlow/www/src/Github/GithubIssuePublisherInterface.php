<?php

namespace App\Github;

use App\Entity\User;

interface GithubIssuePublisherInterface
{
    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function createDraftIssue(User $user, array $payload): array;
}
