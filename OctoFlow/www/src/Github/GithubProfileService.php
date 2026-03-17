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
        private readonly GithubRegistryService $registryService,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function buildProfilePayload(User $user): array
    {
        $repositories = $this->registryService->buildCatalog($user);
        $defaultRepository = $this->registryService->resolveDefaultRepository(
            $user,
            trim((string) $user->getGithubRepositoryOwner()),
        );

        return [
            'repositoryOwner' => $user->getGithubRepositoryOwner(),
            'repositoryName' => $defaultRepository['name'] ?? null,
            'defaultRepositoryKey' => $defaultRepository['nameWithOwner'] ?? null,
            'repositories' => $repositories,
            'tokenConfigured' => $user->hasGithubTokenConfigured(),
            'workspaceReady' => $user->hasGithubTokenConfigured() && $defaultRepository !== null,
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
        $token = trim((string) ($payload['token'] ?? ''));
        $clearToken = (bool) ($payload['clearToken'] ?? false);

        $user
            ->setGithubRepositoryOwner($repositoryOwner !== '' ? $repositoryOwner : null);

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
        $resolvedRepositoryOwner = trim((string) $repositoryOwner);
        $resolvedRepositoryName = trim((string) $repositoryName);

        if ($resolvedRepositoryOwner === '' || $resolvedRepositoryName === '') {
            $defaultRepository = $this->registryService->resolveDefaultRepository(
                $user,
                $resolvedRepositoryOwner !== '' ? $resolvedRepositoryOwner : trim((string) $user->getGithubRepositoryOwner()),
            );

            if ($defaultRepository !== null) {
                $resolvedRepositoryOwner = $defaultRepository['ownerLogin'] ?? '';
                $resolvedRepositoryName = $defaultRepository['name'] ?? '';
            }
        }

        if ($resolvedRepositoryOwner === '' || $resolvedRepositoryName === '') {
            throw new GithubConfigurationException('Register at least one GitHub repository in your profile before using the GitHub workspace.');
        }

        $repositoryKey = sprintf('%s/%s', $resolvedRepositoryOwner, $resolvedRepositoryName);
        $registeredRepositories = $this->registryService->buildCatalog($user);
        $selectedRepository = null;

        foreach ($registeredRepositories as $repository) {
            if (($repository['nameWithOwner'] ?? null) === $repositoryKey) {
                $selectedRepository = $repository;
                break;
            }
        }

        if ($selectedRepository === null) {
            throw new GithubConfigurationException('Register the selected GitHub repository in your profile before using the GitHub workspace.');
        }

        if ((bool) ($selectedRepository['isIgnored'] ?? false)) {
            throw new GithubConfigurationException('The selected repository is ignored in this account.');
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
