<?php

namespace App\Repository;

use App\Entity\UISettings;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UISettings>
 */
final class UISettingsRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UISettings::class);
    }

    public function findOneByUser(User $user): ?UISettings
    {
        return $this->findOneBy(['user' => $user]);
    }
}
