<?php

namespace App\Github;

use App\Account\UserEmailManager;
use App\Entity\User;
use App\Github\Exception\GithubConfigurationException;
use Doctrine\ORM\EntityManagerInterface;

final class GithubLinkedEmailService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly GithubTokenCipher $tokenCipher,
        private readonly GithubUserEmailClient $githubUserEmailClient,
        private readonly UserEmailManager $userEmailManager,
    ) {
    }

    /**
     * @return list<string>
     */
    public function linkVerifiedEmails(User $user): array
    {
        $encryptedToken = trim((string) $user->getGithubTokenEncrypted());
        if ($encryptedToken === '') {
            throw new GithubConfigurationException('Configure your GitHub token before linking GitHub emails.');
        }

        $emails = $this->githubUserEmailClient->fetchEmails($this->tokenCipher->decrypt($encryptedToken));

        $linkedEmails = [];
        foreach ($emails as $email) {
            if (($email['verified'] ?? false) !== true) {
                continue;
            }

            $linkedEmail = $this->userEmailManager->ensureEmail(
                $user,
                $email['email'],
                ['github'],
                true,
                false,
            );

            $linkedEmails[] = $linkedEmail->getEmail();
        }

        if ($linkedEmails === []) {
            throw new \InvalidArgumentException('GitHub did not return any verified email that can be linked to this user.');
        }

        $this->entityManager->flush();

        return array_values(array_unique($linkedEmails));
    }
}
