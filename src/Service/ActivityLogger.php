<?php

namespace App\Service;

use App\Entity\ActivityLog;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Writes entries to the "Recent activity" feed on the admin dashboard.
 */
class ActivityLogger
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Security $security,
    ) {
    }

    /**
     * Records $action as done by the signed-in user (or $username).
     * The entry is saved on the next flush().
     */
    public function log(string $action, ?string $username = null): void
    {
        $username ??= $this->security->getUser()?->getUserIdentifier() ?? 'system';

        $this->entityManager->persist(new ActivityLog($username, $action));
    }
}
