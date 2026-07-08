<?php

namespace App\Tests\Unit\Security;

use App\Entity\User;
use App\Finance\FinancePermissionResolver;
use App\Security\FinancePermissionVoter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

final class FinancePermissionVoterTest extends TestCase
{
    private FinancePermissionVoter $voter;

    protected function setUp(): void
    {
        $this->voter = new FinancePermissionVoter(new FinancePermissionResolver());
    }

    public function testGrantsReadForFinanceReadUser(): void
    {
        $token = $this->buildToken(['ROLE_FINANCE_READ']);

        $this->assertSame(
            VoterInterface::ACCESS_GRANTED,
            $this->voter->vote($token, null, [FinancePermissionResolver::READ]),
        );
    }

    public function testDeniesWriteForFinanceReadUser(): void
    {
        $token = $this->buildToken(['ROLE_FINANCE_READ']);

        $this->assertSame(
            VoterInterface::ACCESS_DENIED,
            $this->voter->vote($token, null, [FinancePermissionResolver::WRITE]),
        );
    }

    public function testAbstainsForUnsupportedPermission(): void
    {
        $token = $this->buildToken(['ROLE_FINANCE_WRITE']);

        $this->assertSame(
            VoterInterface::ACCESS_ABSTAIN,
            $this->voter->vote($token, null, ['tasks:read']),
        );
    }

    /**
     * @param list<string> $roles
     */
    private function buildToken(array $roles): UsernamePasswordToken
    {
        $user = (new User())
            ->setEmail('finance-user@example.com')
            ->setPassword('not-used')
            ->setRoles($roles);

        return new UsernamePasswordToken($user, 'main', $user->getRoles());
    }
}
