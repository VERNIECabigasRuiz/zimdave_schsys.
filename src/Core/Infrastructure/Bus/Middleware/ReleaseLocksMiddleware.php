<?php

declare(strict_types=1);

namespace App\Core\Infrastructure\Bus\Middleware;

use App\Core\Infrastructure\Lock\TransactionLock;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;

/**
 * Lets the handler take transaction locks, and releases them once the rest of the stack,
 * the database transaction included, has finished.
 */
final class ReleaseLocksMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly TransactionLock $locks)
    {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $this->locks->open();
        try {
            return $stack->next()->handle($envelope, $stack);
        } finally {
            $this->locks->releaseAll();
        }
    }
}
