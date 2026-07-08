<?php

namespace App\Security;

use App\Entity\User;
use App\Finance\FinancePermissionResolver;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

final class FinancePermissionVoter extends Voter
{
    public function __construct(
        private readonly FinancePermissionResolver $financePermissionResolver,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $subject === null && in_array($attribute, [FinancePermissionResolver::READ, FinancePermissionResolver::WRITE], true);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        return $this->financePermissionResolver->isGranted($user, $attribute);
    }
}
