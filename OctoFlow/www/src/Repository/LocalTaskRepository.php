<?php

namespace App\Repository;

use App\Entity\LocalTask;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<LocalTask>
 */
class LocalTaskRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LocalTask::class);
    }

    /**
     * @return list<LocalTask>
     */
    public function findUnsyncedByOwner(User $owner): array
    {
        return $this->createQueryBuilder('task')
            ->andWhere('task.owner = :owner')
            ->andWhere('task.syncState != :syncState')
            ->setParameter('owner', $owner)
            ->setParameter('syncState', LocalTask::SYNC_STATE_SYNCED)
            ->orderBy('task.updatedAt', 'DESC')
            ->addOrderBy('task.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<LocalTask>
     */
    public function findSyncableByOwner(User $owner): array
    {
        return $this->createQueryBuilder('task')
            ->andWhere('task.owner = :owner')
            ->andWhere('task.syncState IN (:states)')
            ->setParameter('owner', $owner)
            ->setParameter('states', [LocalTask::SYNC_STATE_PENDING, LocalTask::SYNC_STATE_FAILED])
            ->orderBy('task.createdAt', 'ASC')
            ->addOrderBy('task.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findOneByIdAndOwner(int $taskId, User $owner): ?LocalTask
    {
        return $this->createQueryBuilder('task')
            ->andWhere('task.id = :taskId')
            ->andWhere('task.owner = :owner')
            ->setParameter('taskId', $taskId)
            ->setParameter('owner', $owner)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
