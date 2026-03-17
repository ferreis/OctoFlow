<?php

namespace App\Github;

use App\Entity\Github;
use App\Entity\User;
use App\Repository\GithubRepository;
use Doctrine\ORM\EntityManagerInterface;

class GithubRegistryService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly GithubRepository $githubRepository,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function buildCatalog(User $user, bool $includeIgnored = true): array
    {
        $repositories = $includeIgnored
            ? $this->githubRepository->findAllByOwner($user)
            : $this->githubRepository->findActiveByOwner($user);

        return array_map(fn (Github $github): array => $this->buildPayload($github), $repositories);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function resolveDefaultRepository(User $user, ?string $preferredOwner = null): ?array
    {
        $normalizedPreferredOwner = trim((string) $preferredOwner);
        $catalog = $this->buildCatalog($user, false);

        foreach ($catalog as $repository) {
            if ($normalizedPreferredOwner !== '' && trim((string) ($repository['ownerLogin'] ?? '')) !== $normalizedPreferredOwner) {
                continue;
            }

            return $repository;
        }

        if ($normalizedPreferredOwner !== '') {
            return $this->resolveDefaultRepository($user);
        }

        return null;
    }

    public function findOwnedRepository(User $user, int $id): ?Github
    {
        return $this->githubRepository->findOneOwnedBy($user, $id);
    }

    public function createRepository(User $user, array $payload): Github
    {
        $repository = (new Github())
            ->setOwner($user);

        $this->applyPayload($user, $repository, $payload);
        $this->entityManager->persist($repository);
        $this->entityManager->flush();

        return $repository;
    }

    public function updateRepository(User $user, Github $repository, array $payload): Github
    {
        if ($repository->getOwner()?->getId() !== $user->getId()) {
            throw new \InvalidArgumentException('The selected GitHub repository does not belong to the authenticated user.');
        }

        $this->applyPayload($user, $repository, $payload);
        $this->entityManager->flush();

        return $repository;
    }

    public function deleteRepository(User $user, Github $repository): void
    {
        if ($repository->getOwner()?->getId() !== $user->getId()) {
            throw new \InvalidArgumentException('The selected GitHub repository does not belong to the authenticated user.');
        }

        $this->entityManager->remove($repository);
        $this->entityManager->flush();
    }

    /**
     * @return array<string, mixed>
     */
    public function buildPayload(Github $repository): array
    {
        $normalizedFromUrl = $this->parseGithubRepositoryReference($repository->getUrl());
        $ownerLogin = $normalizedFromUrl['ownerLogin'] ?? $repository->getOwnerLogin();
        $name = $normalizedFromUrl['name'] ?? $repository->getName();
        $nameWithOwner = ($ownerLogin !== '' && $name !== '')
            ? sprintf('%s/%s', $ownerLogin, $name)
            : $repository->getNameWithOwner();
        $url = $normalizedFromUrl['url'] ?? $repository->getUrl();

        return [
            'id' => $repository->getId(),
            'ownerLogin' => $ownerLogin,
            'name' => $name,
            'nameWithOwner' => $nameWithOwner,
            'url' => $url,
            'isIgnored' => $repository->isIgnored(),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function applyPayload(User $user, Github $repository, array $payload): void
    {
        $ownerLogin = trim((string) ($payload['ownerLogin'] ?? $repository->getOwnerLogin()));
        $name = trim((string) ($payload['name'] ?? $repository->getName()));
        $url = trim((string) ($payload['url'] ?? $repository->getUrl()));
        $isIgnored = array_key_exists('isIgnored', $payload)
            ? (bool) $payload['isIgnored']
            : $repository->isIgnored();

        if ($url !== '') {
            $resolvedFromUrl = $this->parseGithubRepositoryReference($url);
            if ($resolvedFromUrl === null) {
                throw new \InvalidArgumentException('The GitHub repository URL must contain the pattern owner/repository.');
            }

            $ownerLogin = $resolvedFromUrl['ownerLogin'];
            $name = $resolvedFromUrl['name'];
            $url = $resolvedFromUrl['url'];
        }

        if ($ownerLogin === '' || $name === '') {
            throw new \InvalidArgumentException('ownerLogin and name are required for the GitHub repository.');
        }

        $repository
            ->setOwnerLogin($ownerLogin)
            ->setName($name)
            ->setUrl($url !== '' ? $url : sprintf('https://github.com/%s/%s', $ownerLogin, $name))
            ->setIsIgnored($isIgnored);

        $existingRepository = $this->githubRepository->findOneByOwnerAndRepository(
            $user,
            $repository->getOwnerLogin(),
            $repository->getName(),
        );
        if ($existingRepository instanceof Github && $existingRepository->getId() !== $repository->getId()) {
            throw new \InvalidArgumentException('This GitHub repository is already registered for the authenticated user.');
        }
    }

    /**
     * @return array{ownerLogin: string, name: string, url: string}|null
     */
    private function parseGithubRepositoryReference(string $value): ?array
    {
        $normalizedValue = trim($value);
        if ($normalizedValue === '') {
            return null;
        }

        $pattern = '~github\.com[:/]+(?P<owner>[^/\s]+)/(?P<name>[^/\s?#]+)~i';
        if (!preg_match($pattern, $normalizedValue, $matches)) {
            return null;
        }

        $ownerLogin = trim((string) ($matches['owner'] ?? ''));
        $name = trim((string) ($matches['name'] ?? ''));
        if ($ownerLogin === '' || $name === '') {
            return null;
        }

        $normalizedName = preg_replace('/\.git$/i', '', $name);
        $normalizedName = trim((string) $normalizedName);
        if ($normalizedName === '') {
            return null;
        }

        return [
            'ownerLogin' => $ownerLogin,
            'name' => $normalizedName,
            'url' => sprintf('https://github.com/%s/%s', $ownerLogin, $normalizedName),
        ];
    }
}
