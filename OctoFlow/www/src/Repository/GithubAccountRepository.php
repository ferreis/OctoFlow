<?php

namespace App\Repository;

use App\Entity\GithubAccount;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GithubAccount>
 */
class GithubAccountRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GithubAccount::class);
    }

    /**
     * @return list<GithubAccount>
     */
    public function findAllByOwner(User $owner): array
    {
        return $this->createQueryBuilder('account')
            ->andWhere('account.owner = :owner')
            ->setParameter('owner', $owner)
            ->addOrderBy('account.accountLogin', 'ASC')
            ->addOrderBy('account.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findOneOwnedBy(User $owner, int $id): ?GithubAccount
    {
        return $this->createQueryBuilder('account')
            ->andWhere('account.owner = :owner')
            ->andWhere('account.id = :id')
            ->setParameter('owner', $owner)
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findOneByOwnerAndLogin(User $owner, string $accountLogin): ?GithubAccount
    {
        return $this->createQueryBuilder('account')
            ->andWhere('account.owner = :owner')
            ->andWhere('account.accountLogin = :accountLogin')
            ->setParameter('owner', $owner)
            ->setParameter('accountLogin', trim($accountLogin))
            ->getQuery()
            ->getOneOrNullResult();
    }
}
