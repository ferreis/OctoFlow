<?php

namespace App\Security;

use App\Entity\AccessTokenBlacklist;
use App\Repository\AccessTokenBlacklistRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

class AccessTokenBlacklistManager
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AccessTokenBlacklistRepository $blacklistRepository,
        #[Autowire('%env(int:JWT_TOKEN_TTL)%')]
        private readonly int $accessTokenTtl,
    ) {
    }

    public function isBlacklisted(string $jwt): bool
    {
        return $this->blacklistRepository->findActiveByHash($this->hashToken($jwt)) !== null;
    }

    public function blacklist(string $jwt, ?\DateTimeImmutable $expiresAt = null, string $reason = 'missing_http_only_cookie'): void
    {
        $tokenHash = $this->hashToken($jwt);
        $existing = $this->blacklistRepository->findOneBy(['tokenHash' => $tokenHash]);

        if ($existing instanceof AccessTokenBlacklist) {
            if ($expiresAt !== null && $expiresAt > $existing->getExpiresAt()) {
                $existing->setExpiresAt($expiresAt);
                $existing->setReason($reason);
                $this->entityManager->flush();
            }

            return;
        }

        $entry = (new AccessTokenBlacklist())
            ->setTokenHash($tokenHash)
            ->setReason($reason)
            ->setCreatedAt(new \DateTimeImmutable())
            ->setExpiresAt($expiresAt ?? new \DateTimeImmutable(sprintf('+%d seconds', $this->accessTokenTtl)));

        $this->entityManager->persist($entry);
        $this->entityManager->flush();
    }

    private function hashToken(string $jwt): string
    {
        return hash('sha256', $jwt);
    }
}
