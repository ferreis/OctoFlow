<?php

namespace App\Github;

use App\Entity\User;
use App\Github\Exception\GithubConfigurationException;
use Doctrine\ORM\EntityManagerInterface;

final class GithubProfileService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly GithubTokenCipher $tokenCipher,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function buildProfilePayload(User $user): array
    {
        return [
            'repositoryOwner' => $user->getGithubRepositoryOwner(),
            'repositoryName' => $user->getGithubRepositoryName(),
            'tokenConfigured' => $user->hasGithubTokenConfigured(),
            'workspaceReady' => $user->hasGithubWorkspaceConfiguration(),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function updateProfile(User $user, array $payload): array
    {
        $repositoryOwner = trim((string) ($payload['repositoryOwner'] ?? ''));
        $repositoryName = trim((string) ($payload['repositoryName'] ?? ''));
        $token = trim((string) ($payload['token'] ?? ''));
        $clearToken = (bool) ($payload['clearToken'] ?? false);

        if ($repositoryOwner === '' || $repositoryName === '') {
            throw new \InvalidArgumentException('Repository owner and repository name are required.');
        }

        $user
            ->setGithubRepositoryOwner($repositoryOwner)
            ->setGithubRepositoryName($repositoryName);

        if ($token !== '') {
            $user->setGithubTokenEncrypted($this->tokenCipher->encrypt($token));
        } elseif ($clearToken) {
            $user->setGithubTokenEncrypted(null);
        } elseif (!$user->hasGithubTokenConfigured()) {
            throw new \InvalidArgumentException('GitHub token is required the first time you configure your profile.');
        }

        $this->entityManager->flush();

        return $this->buildProfilePayload($user);
    }

    public function buildRuntimeConfiguration(User $user, ?string $repositoryOwner = null, ?string $repositoryName = null): GithubRuntimeConfiguration
    {
        $resolvedRepositoryOwner = trim($repositoryOwner ?? (string) $user->getGithubRepositoryOwner());
        $resolvedRepositoryName = trim($repositoryName ?? (string) $user->getGithubRepositoryName());

        if ($resolvedRepositoryOwner === '' || $resolvedRepositoryName === '') {
            throw new GithubConfigurationException('Configure the GitHub repository owner and repository name in your profile before using the GitHub workspace.');
        }

        return new GithubRuntimeConfiguration(
            token: $this->requireToken($user),
            repositoryOwner: $resolvedRepositoryOwner,
            repositoryName: $resolvedRepositoryName,
        );
    }

    public function requireToken(User $user): string
    {
        $encryptedToken = trim((string) $user->getGithubTokenEncrypted());
        if ($encryptedToken === '') {
            throw new GithubConfigurationException('Configure your GitHub token in the profile settings before using the GitHub workspace.');
        }

        return $this->tokenCipher->decrypt($encryptedToken);
    }
}
