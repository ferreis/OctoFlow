<?php

namespace App\Repository;

use App\Entity\RefreshToken;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RefreshToken>
 */
class RefreshTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RefreshToken::class);
    }

    public function findValidByHash(string $tokenHash): ?RefreshToken
    {
        return $this->createQueryBuilder('rt')
            ->andWhere('rt.tokenHash = :tokenHash')
            ->andWhere('rt.revokedAt IS NULL')
            ->andWhere('rt.expiresAt > :now')
            ->setParameter('tokenHash', $tokenHash)
            ->setParameter('now', new \DateTimeImmutable())
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Encontra um token pelo hash, independente se está revogado ou expirado.
     * Útil para detectar tentativas de reuso de tokens revogados.
     */
    public function findByHash(string $tokenHash): ?RefreshToken
    {
        return $this->createQueryBuilder('rt')
            ->andWhere('rt.tokenHash = :tokenHash')
            ->setParameter('tokenHash', $tokenHash)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Encontra todos os tokens de uma família específica.
     * Utilizado para revogação em cascata quando reuso é detectado.
     *
     * @return RefreshToken[]
     */
    public function findByTokenFamilyId(?string $tokenFamilyId): array
    {
        if ($tokenFamilyId === null) {
            return [];
        }

        return $this->createQueryBuilder('rt')
            ->andWhere('rt.tokenFamilyId = :tokenFamilyId')
            ->setParameter('tokenFamilyId', $tokenFamilyId)
            ->getQuery()
            ->getResult();
    }

    public function revokeAllForUser(User $user): int
    {
        return $this->createQueryBuilder('rt')
            ->update()
            ->set('rt.revokedAt', ':now')
            ->where('rt.user = :user')
            ->andWhere('rt.revokedAt IS NULL')
            ->setParameter('user', $user)
            ->setParameter('now', new \DateTimeImmutable())
            ->getQuery()
            ->execute();
    }

    public function revokeExpiredTokens(): int
    {
        return $this->createQueryBuilder('rt')
            ->update()
            ->set('rt.revokedAt', ':now')
            ->where('rt.expiresAt <= :now')
            ->andWhere('rt.revokedAt IS NULL')
            ->setParameter('now', new \DateTimeImmutable())
            ->getQuery()
            ->execute();
    }

    /**
     * Deleta tokens que já expiram há mais de 24 horas.
     * Garbage collection para evitar acúmulo no banco.
     */
    public function deleteExpiredTokens(): int
    {
        $cutoffDate = new \DateTimeImmutable('-24 hours');

        return $this->createQueryBuilder('rt')
            ->delete()
            ->where('rt.expiresAt <= :cutoff')
            ->setParameter('cutoff', $cutoffDate)
            ->getQuery()
            ->execute();
    }
}
