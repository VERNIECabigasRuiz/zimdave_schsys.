<?php

declare(strict_types=1);

namespace App\Core\Domain\Service\ActivityLog;

/**
 * Writes entries to the "Recent activity" feed on the admin dashboard.
 */
interface ActivityLoggerInterface
{
    /**
     * Records $action as done by the signed-in user (or $username, or "system" when nobody is signed in).
     * The entry is saved on the next flush().
     */
    public function log(string $action, ?string $username = null): void;
}
