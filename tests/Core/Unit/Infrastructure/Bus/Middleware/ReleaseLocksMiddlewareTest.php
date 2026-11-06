<?php

declare(strict_types=1);

namespace App\Tests\Core\Unit\Infrastructure\Bus\Middleware;

use App\Core\Infrastructure\Bus\Middleware\ReleaseLocksMiddleware;
use App\Core\Infrastructure\Lock\TransactionLock;
use App\Tests\Core\Unit\Infrastructure\Bus\Fixture\CallbackMiddleware;
use App\Tests\Core\Unit\Infrastructure\Bus\Fixture\SampleUseCase;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\StackMiddleware;

final class ReleaseLocksMiddlewareTest extends TestCase
{
    public function testReleasesAfterTheStack(): void
    {
        // Expect
        $locks = $this->createMock(TransactionLock::class);
        $locks->expects(self::once())->method('releaseAll');

        // Initialize
        $envelope = new Envelope(new SampleUseCase());
        $stack = new StackMiddleware(new CallbackMiddleware(static fn (Envelope $handled): Envelope => $handled));

        // Execute
        $returned = (new ReleaseLocksMiddleware($locks))->handle($envelope, $stack);

        // Assert
        self::assertSame($envelope, $returned);
    }

    public function testReleasesWhenTheStackThrows(): void
    {
        // Expect
        $locks = $this->createMock(TransactionLock::class);
        $locks->expects(self::once())->method('releaseAll');
        $this->expectException(\RuntimeException::class);

        // Initialize
        $stack = new StackMiddleware(new CallbackMiddleware(
            static fn (Envelope $handled): Envelope => throw new \RuntimeException('Rolled back.'),
        ));

        // Execute
        (new ReleaseLocksMiddleware($locks))->handle(new Envelope(new SampleUseCase()), $stack);
    }
}
