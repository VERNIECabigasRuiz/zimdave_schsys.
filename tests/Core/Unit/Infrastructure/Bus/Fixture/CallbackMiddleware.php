<?php

declare(strict_types=1);

namespace App\Tests\Core\Unit\Infrastructure\Bus\Fixture;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;

/**
 * Stands in for the rest of the middleware stack and counts how often it runs.
 */
final class CallbackMiddleware implements MiddlewareInterface
{
    public int $calls = 0;

    /** @param \Closure(Envelope): Envelope $callback */
    public function __construct(private readonly \Closure $callback)
    {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        ++$this->calls;

        return ($this->callback)($envelope);
    }
}
