<?php

declare(strict_types=1);

namespace App\Tests\Learners\Integration\Application;

use Doctrine\ORM\Event\OnFlushEventArgs;

/** Plays another request that saves an account with the same username just before this change is written, once. */
final class UsernameTakenOnFlush
{
    private bool $done = false;

    public function __construct(private readonly string $username)
    {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        if ($this->done) {
            return;
        }
        $this->done = true;
        $args->getObjectManager()->getConnection()->insert('user', [
            'username' => $this->username,
            'roles' => '["ROLE_TEACHER"]',
            'password' => 'x',
            'full_name' => 'Someone Else',
            'active' => 1,
            'must_change_password' => 0,
            'created_at' => '2026-10-16 08:00:00',
        ]);
    }
}
