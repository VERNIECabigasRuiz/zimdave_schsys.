<?php

declare(strict_types=1);

namespace App\Core\Infrastructure\ActivityLog;

use App\Core\Domain\Model\ActivityLog;
use App\Core\Domain\Model\Repository\ActivityLogRepositoryInterface;
use App\Core\Domain\Service\ActivityLog\ActivityLoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;

class ActivityLogger implements ActivityLoggerInterface
{
    public function __construct(
        private readonly ActivityLogRepositoryInterface $entries,
        private readonly Security $security,
    ) {
    }

    public function log(string $action, ?string $username = null): void
    {
        $username ??= $this->security->getUser()?->getUserIdentifier() ?? 'system';

        $this->entries->record(new ActivityLog($username, $action));
    }
}
