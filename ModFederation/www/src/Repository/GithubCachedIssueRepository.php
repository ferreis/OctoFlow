<?php

namespace App\Repository;

use App\Entity\GithubCachedIssue;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GithubCachedIssue>
 */
final class GithubCachedIssueRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GithubCachedIssue::class);
    }

    /**
     * @return list<GithubCachedIssue>
     */
    public function findActiveByOwner(User $owner): array
    {
        return $this->createQueryBuilder('issue')
            ->andWhere('issue.owner = :owner')
            ->andWhere('issue.active = true')
            ->setParameter('owner', $owner)
            ->orderBy('issue.githubUpdatedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<GithubCachedIssue>
     */
    public function findAssignedActiveByOwner(User $owner): array
    {
        return $this->createQueryBuilder('issue')
            ->andWhere('issue.owner = :owner')
            ->andWhere('issue.active = true')
            ->andWhere('issue.assignedToViewer = true')
            ->setParameter('owner', $owner)
            ->orderBy('issue.githubUpdatedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<GithubCachedIssue>
     */
    public function findActiveByOwnerAndRepositoryKey(User $owner, string $repositoryKey): array
    {
        return $this->createQueryBuilder('issue')
            ->andWhere('issue.owner = :owner')
            ->andWhere('issue.active = true')
            ->andWhere('issue.repositoryKey = :repositoryKey')
            ->setParameter('owner', $owner)
            ->setParameter('repositoryKey', trim($repositoryKey))
            ->orderBy('issue.githubUpdatedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findOneByOwnerAndGithubIssueId(User $owner, string $githubIssueId): ?GithubCachedIssue
    {
        return $this->createQueryBuilder('issue')
            ->andWhere('issue.owner = :owner')
            ->andWhere('issue.githubIssueId = :githubIssueId')
            ->setParameter('owner', $owner)
            ->setParameter('githubIssueId', trim($githubIssueId))
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function markAllInactiveForOwner(User $owner): void
    {
        $this->createQueryBuilder('issue')
            ->update()
            ->set('issue.active', ':active')
            ->andWhere('issue.owner = :owner')
            ->setParameter('active', false)
            ->setParameter('owner', $owner)
            ->getQuery()
            ->execute();
    }

    public function markRepositoryInactiveForOwner(User $owner, string $repositoryKey): void
    {
        $this->createQueryBuilder('issue')
            ->update()
            ->set('issue.active', ':active')
            ->andWhere('issue.owner = :owner')
            ->andWhere('issue.repositoryKey = :repositoryKey')
            ->setParameter('active', false)
            ->setParameter('owner', $owner)
            ->setParameter('repositoryKey', trim($repositoryKey))
            ->getQuery()
            ->execute();
    }

    public function clearAssignedFlagForOwner(User $owner): void
    {
        $this->createQueryBuilder('issue')
            ->update()
            ->set('issue.assignedToViewer', ':assigned')
            ->andWhere('issue.owner = :owner')
            ->setParameter('assigned', false)
            ->setParameter('owner', $owner)
            ->getQuery()
            ->execute();
    }
}
