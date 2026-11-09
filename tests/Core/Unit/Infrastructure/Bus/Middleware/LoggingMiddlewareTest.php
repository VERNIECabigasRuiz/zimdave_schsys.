<?php

declare(strict_types=1);

namespace App\Tests\Core\Unit\Infrastructure\Bus\Middleware;

use App\Core\Application\Bus\Result;
use App\Core\Infrastructure\Bus\Middleware\LoggingMiddleware;
use App\Tests\Core\Unit\Infrastructure\Bus\Fixture\CallbackMiddleware;
use App\Tests\Core\Unit\Infrastructure\Bus\Fixture\SampleUseCase;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\StackMiddleware;
use Symfony\Component\Messenger\Stamp\HandledStamp;

final class LoggingMiddlewareTest extends TestCase
{
    private TestHandler $records;
    private LoggingMiddleware $middleware;

    protected function setUp(): void
    {
        $this->records = new TestHandler();
        $this->middleware = new LoggingMiddleware(new Logger('bus', [$this->records]));
    }

    public function testLogsDispatchWithLogArray(): void
    {
        // Execute
        $this->middleware->handle(new Envelope(new SampleUseCase()), $this->stackReturning(Result::success()));

        // Assert
        $first = $this->records->getRecords()[0];
        self::assertSame(Level::Info, $first->level);
        self::assertSame('Dispatching ' . SampleUseCase::class, $first->message);
        self::assertSame(['id' => 5], $first->context);
    }

    public function testLogsMessageWithoutLogArray(): void
    {
        // Execute
        $this->middleware->handle(new Envelope(new \stdClass()), $this->stackReturning(Result::success()));

        // Assert
        self::assertSame([], $this->records->getRecords()[0]->context);
    }

    public function testLogsSuccess(): void
    {
        // Execute
        $this->middleware->handle(new Envelope(new SampleUseCase()), $this->stackReturning(Result::success()));

        // Assert
        $last = $this->records->getRecords()[1];
        self::assertSame(Level::Info, $last->level);
        self::assertSame('Handled ' . SampleUseCase::class . ' successfully', $last->message);
    }

    public function testLogsFailureWithCode(): void
    {
        // Initialize
        $failure = Result::failure(new \RuntimeException('broken'), 'X_CODE');

        // Execute
        $this->middleware->handle(new Envelope(new SampleUseCase()), $this->stackReturning($failure));

        // Assert
        $last = $this->records->getRecords()[1];
        self::assertSame(Level::Warning, $last->level);
        self::assertSame('Handled ' . SampleUseCase::class . ' with failure', $last->message);
        self::assertSame(['errorCode' => 'X_CODE'], $last->context);
    }

    private function stackReturning(Result $result): StackMiddleware
    {
        return new StackMiddleware(new CallbackMiddleware(
            static fn (Envelope $envelope): Envelope => $envelope->with(new HandledStamp($result, 'handler')),
        ));
    }
}
