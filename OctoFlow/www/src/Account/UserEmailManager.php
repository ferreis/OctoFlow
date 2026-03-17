<?php

namespace App\Account;

use App\Account\Exception\UserEmailConflictException;
use App\Entity\User;
use App\Entity\UserEmail;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;

final class UserEmailManager
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserRepository $userRepository,
    ) {
    }

    public function initialize(User $user): bool
    {
        $changed = false;
        $currentPrimaryEmail = mb_strtolower(trim($user->getEmail()));

        if ($currentPrimaryEmail === '') {
            throw new \InvalidArgumentException('The user must have a default email before initializing linked emails.');
        }

        $primaryEmailAddress = null;
        foreach ($user->getEmailAddresses() as $emailAddress) {
            if (!$emailAddress->isPrimary()) {
                continue;
            }

            if ($primaryEmailAddress === null) {
                $primaryEmailAddress = $emailAddress;
                continue;
            }

            $emailAddress->setIsPrimary(false);
            $changed = true;
        }

        $currentEmailAddress = $this->findEmailAddress($user, $currentPrimaryEmail);
        if ($currentEmailAddress === null) {
            $currentEmailAddress = (new UserEmail())
                ->setEmail($currentPrimaryEmail)
                ->setIsVerified(true)
                ->setProviders($this->defaultProvidersFor($user));

            $user->addEmailAddress($currentEmailAddress);
            $this->entityManager->persist($currentEmailAddress);
            $changed = true;
        }

        foreach ($this->defaultProvidersFor($user) as $provider) {
            if ($currentEmailAddress->hasProvider($provider)) {
                continue;
            }

            $currentEmailAddress->addProvider($provider);
            $changed = true;
        }

        if ($primaryEmailAddress === null || $primaryEmailAddress->getEmail() !== $currentPrimaryEmail) {
            $this->markPrimaryEmail($user, $currentEmailAddress);
            $changed = true;
        }

        return $changed;
    }

    /**
     * @param list<string> $providers
     */
    public function ensureEmail(
        User $user,
        string $email,
        array $providers = ['system'],
        bool $isVerified = true,
        bool $makePrimary = false,
    ): UserEmail {
        $this->initialize($user);

        $normalizedEmail = mb_strtolower(trim($email));
        if ($normalizedEmail === '') {
            throw new \InvalidArgumentException('A valid email is required.');
        }

        $conflictingUser = $this->userRepository->findOneByEmail($normalizedEmail);
        if ($conflictingUser !== null && $conflictingUser->getId() !== $user->getId()) {
            throw new UserEmailConflictException('This email is already linked to another user.');
        }

        $emailAddress = $this->findEmailAddress($user, $normalizedEmail);
        if ($emailAddress === null) {
            $emailAddress = (new UserEmail())
                ->setEmail($normalizedEmail)
                ->setProviders($providers)
                ->setIsVerified($isVerified);

            $user->addEmailAddress($emailAddress);
            $this->entityManager->persist($emailAddress);
        } else {
            foreach ($providers as $provider) {
                $emailAddress->addProvider($provider);
            }

            if ($isVerified) {
                $emailAddress->setIsVerified(true);
            }
        }

        if ($makePrimary || !$this->hasPrimaryEmail($user)) {
            $this->markPrimaryEmail($user, $emailAddress);
        }

        return $emailAddress;
    }

    public function setPrimaryEmail(User $user, string $email): UserEmail
    {
        $this->initialize($user);

        $normalizedEmail = mb_strtolower(trim($email));
        $emailAddress = $this->findEmailAddress($user, $normalizedEmail);
        if ($emailAddress === null) {
            throw new \InvalidArgumentException('The selected email is not linked to the current user.');
        }

        $this->markPrimaryEmail($user, $emailAddress);

        return $emailAddress;
    }

    public function hasProvider(User $user, string $provider): bool
    {
        $this->initialize($user);

        foreach ($user->getEmailAddresses() as $emailAddress) {
            if ($emailAddress->hasProvider($provider)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array{id: int|null, email: string, providers: list<string>, isPrimary: bool, isVerified: bool}>
     */
    public function buildPayload(User $user): array
    {
        $this->initialize($user);

        $payload = [];
        foreach ($user->getEmailAddresses() as $emailAddress) {
            $payload[] = [
                'id' => $emailAddress->getId(),
                'email' => $emailAddress->getEmail(),
                'providers' => $emailAddress->getProviders(),
                'isPrimary' => $emailAddress->isPrimary(),
                'isVerified' => $emailAddress->isVerified(),
            ];
        }

        usort($payload, static function (array $left, array $right): int {
            if ($left['isPrimary'] !== $right['isPrimary']) {
                return $left['isPrimary'] ? -1 : 1;
            }

            return strcmp($left['email'], $right['email']);
        });

        return $payload;
    }

    private function hasPrimaryEmail(User $user): bool
    {
        foreach ($user->getEmailAddresses() as $emailAddress) {
            if ($emailAddress->isPrimary()) {
                return true;
            }
        }

        return false;
    }

    private function markPrimaryEmail(User $user, UserEmail $primaryEmailAddress): void
    {
        foreach ($user->getEmailAddresses() as $emailAddress) {
            $emailAddress->setIsPrimary($emailAddress === $primaryEmailAddress);
        }

        $user->setEmail($primaryEmailAddress->getEmail());
    }

    private function findEmailAddress(User $user, string $email): ?UserEmail
    {
        foreach ($user->getEmailAddresses() as $emailAddress) {
            if ($emailAddress->getEmail() === $email) {
                return $emailAddress;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function defaultProvidersFor(User $user): array
    {
        if ($user->getGoogleSubject() !== null) {
            return ['google'];
        }

        return ['system'];
    }
}
