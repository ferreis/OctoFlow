<?php

namespace App\Github;

use App\Entity\GithubAccount;
use App\Entity\User;
use App\Github\Exception\GithubConfigurationException;
use App\Repository\GithubAccountRepository;
use Doctrine\ORM\EntityManagerInterface;

final class GithubProfileService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly GithubTokenCipher $tokenCipher,
        private readonly GithubRegistryService $registryService,
        private readonly GithubAccountRepository $githubAccountRepository,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function buildProfilePayload(User $user): array
    {
        $accounts = $this->registryService->buildAccountCatalog($user);
        $repositories = $this->registryService->buildCatalog($user);
        $defaultRepository = $this->registryService->resolveDefaultRepository(
            $user,
            trim((string) $user->getGithubRepositoryOwner()),
        );
        $firstAccount = $accounts[0] ?? null;
        $tokenConfigured = count(array_filter(
            $accounts,
            static fn (array $account): bool => ($account['tokenConfigured'] ?? false) === true,
        )) > 0 || $user->hasGithubTokenConfigured();
        $workspaceReady = count(array_filter(
            $accounts,
            static fn (array $account): bool => ($account['workspaceReady'] ?? false) === true,
        )) > 0;

        return [
            'accounts' => $accounts,
            'repositoryOwner' => $firstAccount['accountLogin'] ?? $user->getGithubRepositoryOwner(),
            'repositoryName' => $defaultRepository['name'] ?? null,
            'defaultRepositoryKey' => $defaultRepository['nameWithOwner'] ?? null,
            'repositories' => $repositories,
            'tokenConfigured' => $tokenConfigured,
            'workspaceReady' => $workspaceReady && $defaultRepository !== null,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function updateProfile(User $user, array $payload): array
    {
        $existingPrimaryAccount = $this->registryService->findFirstOwnedAccount($user);
        if ($existingPrimaryAccount instanceof GithubAccount) {
            $this->updateAccount($user, $existingPrimaryAccount, [
                'accountLogin' => $payload['repositoryOwner'] ?? $existingPrimaryAccount->getAccountLogin(),
                'token' => $payload['token'] ?? '',
                'clearToken' => $payload['clearToken'] ?? false,
            ]);

            return $this->buildProfilePayload($user);
        }

        $this->createAccount($user, [
            'accountLogin' => $payload['repositoryOwner'] ?? '',
            'token' => $payload['token'] ?? '',
            'clearToken' => $payload['clearToken'] ?? false,
        ]);

        return $this->buildProfilePayload($user);
    }

    public function findOwnedAccount(User $user, int $id): ?GithubAccount
    {
        return $this->githubAccountRepository->findOneOwnedBy($user, $id);
    }

    /**
     * @return array<string, mixed>
     */
    public function createAccount(User $user, array $payload): array
    {
        $account = (new GithubAccount())->setOwner($user);
        $this->applyAccountPayload($user, $account, $payload, true);
        $this->entityManager->persist($account);
        $this->entityManager->flush();
        $this->syncLegacyGithubConfiguration($user);
        $this->entityManager->flush();

        return $this->registryService->buildAccountPayload($account);
    }

    /**
     * @return array<string, mixed>
     */
    public function updateAccount(User $user, GithubAccount $account, array $payload): array
    {
        if ($account->getOwner()?->getId() !== $user->getId()) {
            throw new \InvalidArgumentException('The selected GitHub account does not belong to the authenticated user.');
        }

        $this->applyAccountPayload($user, $account, $payload, false);
        $this->entityManager->flush();
        $this->syncLegacyGithubConfiguration($user);
        $this->entityManager->flush();

        return $this->registryService->buildAccountPayload($account);
    }

    public function deleteAccount(User $user, GithubAccount $account): void
    {
        if ($account->getOwner()?->getId() !== $user->getId()) {
            throw new \InvalidArgumentException('The selected GitHub account does not belong to the authenticated user.');
        }

        $this->entityManager->remove($account);
        $this->entityManager->flush();
        $this->syncLegacyGithubConfiguration($user);
        $this->entityManager->flush();
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

        $selectedAccountId = (int) ($selectedRepository['accountId'] ?? 0);
        $selectedAccount = $selectedAccountId > 0
            ? $this->githubAccountRepository->findOneOwnedBy($user, $selectedAccountId)
            : null;

        return new GithubRuntimeConfiguration(
            token: $selectedAccount instanceof GithubAccount ? $this->requireAccountToken($selectedAccount) : $this->requireToken($user),
            repositoryOwner: $resolvedRepositoryOwner,
            repositoryName: $resolvedRepositoryName,
        );
    }

    public function requireToken(User $user): string
    {
        $encryptedToken = trim((string) $user->getGithubTokenEncrypted());
        if ($encryptedToken === '') {
            $firstConfiguredAccount = null;
            foreach ($this->githubAccountRepository->findAllByOwner($user) as $account) {
                if ($account->hasTokenConfigured()) {
                    $firstConfiguredAccount = $account;
                    break;
                }
            }

            if (!$firstConfiguredAccount instanceof GithubAccount) {
                throw new GithubConfigurationException('Configure your GitHub token in the profile settings before using the GitHub workspace.');
            }

            return $this->requireAccountToken($firstConfiguredAccount);
        }

        return $this->tokenCipher->decrypt($encryptedToken);
    }

    private function requireAccountToken(GithubAccount $account): string
    {
        $encryptedToken = trim((string) $account->getTokenEncrypted());
        if ($encryptedToken === '') {
            throw new GithubConfigurationException('Configure the token for the selected GitHub account before using the GitHub workspace.');
        }

        return $this->tokenCipher->decrypt($encryptedToken);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function applyAccountPayload(User $user, GithubAccount $account, array $payload, bool $creating): void
    {
        $accountLogin = trim((string) ($payload['accountLogin'] ?? $payload['repositoryOwner'] ?? $account->getAccountLogin()));
        $token = trim((string) ($payload['token'] ?? ''));
        $clearToken = (bool) ($payload['clearToken'] ?? false);

        if ($accountLogin === '') {
            throw new \InvalidArgumentException('The GitHub account login is required.');
        }

        $account->setAccountLogin($accountLogin);

        if ($token !== '') {
            $account->setTokenEncrypted($this->tokenCipher->encrypt($token));
        } elseif ($clearToken) {
            $account->setTokenEncrypted(null);
        } elseif ($creating && !$account->hasTokenConfigured()) {
            throw new \InvalidArgumentException('GitHub token is required the first time you configure an account.');
        }

        $existingAccount = $this->githubAccountRepository->findOneByOwnerAndLogin($user, $account->getAccountLogin());
        if ($existingAccount instanceof GithubAccount && $existingAccount->getId() !== $account->getId()) {
            throw new \InvalidArgumentException('This GitHub account is already registered for the authenticated user.');
        }
    }

    private function syncLegacyGithubConfiguration(User $user): void
    {
        $primaryAccount = $this->registryService->findFirstOwnedAccount($user);
        if (!$primaryAccount instanceof GithubAccount) {
            $user
                ->setGithubRepositoryOwner(null)
                ->setGithubTokenEncrypted(null);

            return;
        }

        $user
            ->setGithubRepositoryOwner($primaryAccount->getAccountLogin())
            ->setGithubTokenEncrypted($primaryAccount->getTokenEncrypted());
    }
}
