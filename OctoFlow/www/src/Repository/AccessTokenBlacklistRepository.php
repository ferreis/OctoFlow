<?php

namespace App\Repository;

use App\Entity\AccessTokenBlacklist;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AccessTokenBlacklist>
 */
class AccessTokenBlacklistRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AccessTokenBlacklist::class);
    }

    public function findActiveByHash(string $tokenHash): ?AccessTokenBlacklist
    {
        return $this->createQueryBuilder('b')
            ->andWhere('b.tokenHash = :tokenHash')
            ->andWhere('b.expiresAt > :now')
            ->setParameter('tokenHash', $tokenHash)
            ->setParameter('now', new \DateTimeImmutable())
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
