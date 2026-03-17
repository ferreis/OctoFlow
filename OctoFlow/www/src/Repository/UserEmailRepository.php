<?php

namespace App\Repository;

use App\Entity\UserEmail;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UserEmail>
 */
class UserEmailRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserEmail::class);
    }

    public function findOneByEmail(string $email): ?UserEmail
    {
        return $this->findOneBy(['email' => mb_strtolower(trim($email))]);
    }
}
