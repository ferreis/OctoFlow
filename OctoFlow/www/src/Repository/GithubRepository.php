<?php

namespace App\Repository;

use App\Entity\Github;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Github>
 */
class GithubRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Github::class);
    }

    /**
     * @return list<Github>
     */
    public function findAllByOwner(User $owner): array
    {
        return $this->createQueryBuilder('github')
            ->andWhere('github.owner = :owner')
            ->setParameter('owner', $owner)
            ->addOrderBy('github.isIgnored', 'ASC')
            ->addOrderBy('github.ownerLogin', 'ASC')
            ->addOrderBy('github.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<Github>
     */
    public function findActiveByOwner(User $owner): array
    {
        return $this->createQueryBuilder('github')
            ->andWhere('github.owner = :owner')
            ->andWhere('github.isIgnored = false')
            ->setParameter('owner', $owner)
            ->addOrderBy('github.ownerLogin', 'ASC')
            ->addOrderBy('github.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findOneOwnedBy(User $owner, int $id): ?Github
    {
        return $this->createQueryBuilder('github')
            ->andWhere('github.owner = :owner')
            ->andWhere('github.id = :id')
            ->setParameter('owner', $owner)
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findOneByOwnerAndRepository(User $owner, string $ownerLogin, string $name): ?Github
    {
        return $this->createQueryBuilder('github')
            ->andWhere('github.owner = :owner')
            ->andWhere('github.ownerLogin = :ownerLogin')
            ->andWhere('github.name = :name')
            ->setParameter('owner', $owner)
            ->setParameter('ownerLogin', trim($ownerLogin))
            ->setParameter('name', trim($name))
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return list<Github>
     */
    public function findAllByAccount(\App\Entity\GithubAccount $account, bool $includeIgnored = true): array
    {
        $queryBuilder = $this->createQueryBuilder('github')
            ->andWhere('github.account = :account')
            ->setParameter('account', $account)
            ->addOrderBy('github.isIgnored', 'ASC')
            ->addOrderBy('github.ownerLogin', 'ASC')
            ->addOrderBy('github.name', 'ASC');

        if (!$includeIgnored) {
            $queryBuilder->andWhere('github.isIgnored = false');
        }

        return $queryBuilder->getQuery()->getResult();
    }
}
