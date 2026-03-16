<?php

namespace App\Account;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

final class UserPayloadBuilder
{
    public function __construct(
        private readonly UserEmailManager $userEmailManager,
        private readonly EntityManagerInterface $entityManager,
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
            'isActive' => $user->isActive(),
            'googleLinked' => $user->getGoogleSubject() !== null,
            'githubLinked' => $this->userEmailManager->hasProvider($user, 'github'),
            'githubTokenConfigured' => $user->hasGithubTokenConfigured(),
            'linkedEmails' => $this->userEmailManager->buildPayload($user),
        ];
    }
}
