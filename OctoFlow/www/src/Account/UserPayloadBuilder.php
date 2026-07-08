<?php

namespace App\Account;

use App\Entity\User;
use App\Finance\FinancePermissionResolver;
use Doctrine\ORM\EntityManagerInterface;

final class UserPayloadBuilder
{
    public function __construct(
        private readonly UserEmailManager $userEmailManager,
        private readonly GooglePasswordSetupManager $googlePasswordSetupManager,
        private readonly EntityManagerInterface $entityManager,
        private readonly FinancePermissionResolver $financePermissionResolver,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(User $user): array
    {
        if ($this->userEmailManager->initialize($user)) {
            $this->entityManager->flush();
        }

        return [
            'id' => $user->getId(),
            'email' => $user->getEmail(),
            'defaultEmail' => $user->getEmail(),
            'roles' => $user->getRoles(),
            'permissions' => $this->financePermissionResolver->resolve($user),
            'isActive' => $user->isActive(),
            'googleLinked' => $user->getGoogleSubject() !== null,
            'passwordLoginEnabled' => $user->isPasswordLoginEnabled(),
            'passwordSetupRequired' => $this->googlePasswordSetupManager->requiresPasswordSetup($user),
            'passwordSetupEmailValidated' => $this->googlePasswordSetupManager->isEmailCodeValidated($user),
            'passwordSetupCodeExpiresAt' => $user->getGooglePasswordSetupCodeExpiresAt()?->format(\DateTimeInterface::ATOM),
            'githubLinked' => $this->userEmailManager->hasProvider($user, 'github'),
            'githubTokenConfigured' => $user->hasGithubTokenConfigured(),
            'linkedEmails' => $this->userEmailManager->buildPayload($user),
            'avatarUrl' => $user->getAvatarPath(),
            'avatarUpdatedAt' => $user->getAvatarUpdatedAt()?->format(\DateTimeInterface::ATOM),
        ];
    }
}
