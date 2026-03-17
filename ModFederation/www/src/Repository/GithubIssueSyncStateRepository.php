<?php

namespace App\Repository;

use App\Entity\GithubIssueSyncState;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GithubIssueSyncState>
 */
class GithubIssueSyncStateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GithubIssueSyncState::class);
    }

    public function findOneByOwnerScope(User $owner, string $scope, string $repositoryKey = ''): ?GithubIssueSyncState
    {
        return $this->createQueryBuilder('state')
            ->andWhere('state.owner = :owner')
            ->andWhere('state.scope = :scope')
            ->andWhere('state.repositoryKey = :repositoryKey')
            ->setParameter('owner', $owner)
            ->setParameter('scope', trim(strtolower($scope)))
            ->setParameter('repositoryKey', trim($repositoryKey))
            ->getQuery()
            ->getOneOrNullResult();
    }
}
