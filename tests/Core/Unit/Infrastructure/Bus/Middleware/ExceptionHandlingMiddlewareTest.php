<?php

declare(strict_types=1);

namespace App\Tests\Core\Unit\Infrastructure\Bus\Middleware;

use App\Core\Application\Bus\Result;
use App\Core\Domain\Exception\DomainException;
use App\Core\Infrastructure\Bus\Middleware\ExceptionHandlingMiddleware;
use App\Tests\Core\Unit\Infrastructure\Bus\Fixture\CallbackMiddleware;
use App\Tests\Core\Unit\Infrastructure\Bus\Fixture\SampleUseCase;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Exception\ValidationFailedException;
use Symfony\Component\Messenger\Middleware\StackMiddleware;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Validator\ConstraintViolationList;

final class ExceptionHandlingMiddlewareTest extends TestCase
{
    private TestHandler $records;
    private ExceptionHandlingMiddleware $middleware;

    protected function setUp(): void
    {
        $this->records = new TestHandler();
        $this->middleware = new ExceptionHandlingMiddleware(
            new Logger('bus', [$this->records]),
            self::createStub(ManagerRegistry::class),
        );
    }

    public function testClosedEntityManagerIsReset(): void
    {
        // Expect
        $registry = $this->registryWith(open: false);
        $registry->expects(self::once())->method('resetManager');

        // Initialize
        $this->middleware = new ExceptionHandlingMiddleware(new Logger('bus', [$this->records]), $registry);

        // Execute
        $result = $this->handleThrowing(new \RuntimeException('duplicate key'));

        // Assert
        self::assertSame('INTERNAL_ERROR', $result->errorCode());
    }

    public function testOpenEntityManagerIsKept(): void
    {
        // Expect
        $registry = $this->registryWith(open: true);
        $registry->expects(self::never())->method('resetManager');

        // Initialize
        $this->middleware = new ExceptionHandlingMiddleware(new Logger('bus', [$this->records]), $registry);

        // Execute
        $result = $this->handleThrowing(new \RuntimeException('crashed'));

        // Assert
        self::assertSame('INTERNAL_ERROR', $result->errorCode());
    }

    public function testPassesResultThrough(): void
    {
        // Initialize
        $handled = Result::success('done');
        $stack = new StackMiddleware(new CallbackMiddleware(
            static fn (Envelope $envelope): Envelope => $envelope->with(new HandledStamp($handled, 'handler')),
        ));

        // Execute
        $envelope = $this->middleware->handle(new Envelope(new SampleUseCase()), $stack);

        // Assert
        self::assertSame($handled, $this->resultOf($envelope));
    }

    public function testDomainExceptionBecomesFailureWithItsCode(): void
    {
        // Initialize
        $refused = new class ('Refused.', 'REFUSED') extends DomainException {
        };
        $wrapped = new HandlerFailedException(new Envelope(new SampleUseCase()), [$refused]);

        // Execute
        $direct = $this->handleThrowing($refused);
        $unwrapped = $this->handleThrowing($wrapped);

        // Assert
        self::assertSame('REFUSED', $direct->errorCode());
        self::assertSame($refused, $direct->exception());
        self::assertSame('REFUSED', $unwrapped->errorCode());
        self::assertSame($refused, $unwrapped->exception());
    }

    public function testValidationFailureBecomesValidationFailed(): void
    {
        // Initialize
        $invalid = new ValidationFailedException(new SampleUseCase(), new ConstraintViolationList());

        // Execute
        $result = $this->handleThrowing($invalid);

        // Assert
        self::assertTrue($result->isFailure());
        self::assertSame('VALIDATION_FAILED', $result->errorCode());
    }

    public function testUnexpectedExceptionBecomesInternalErrorAndIsLogged(): void
    {
        // Initialize
        $crash = new \RuntimeException('crashed');

        // Execute
        $result = $this->handleThrowing($crash);

        // Assert
        self::assertSame('INTERNAL_ERROR', $result->errorCode());
        self::assertSame($crash, $result->exception());
        $records = $this->records->getRecords();
        self::assertCount(1, $records);
        self::assertSame(Level::Error, $records[0]->level);
        self::assertStringContainsString(SampleUseCase::class, $records[0]->message);
    }

    private function registryWith(bool $open): ManagerRegistry&MockObject
    {
        $manager = self::createStub(EntityManagerInterface::class);
        $manager->method('isOpen')->willReturn($open);
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManager')->willReturn($manager);

        return $registry;
    }

    /** Handles a message whose handler throws $exception. */
    private function handleThrowing(\Throwable $exception): Result
    {
        $stack = new StackMiddleware(new CallbackMiddleware(
            static fn (Envelope $envelope): Envelope => throw $exception,
        ));

        return $this->resultOf($this->middleware->handle(new Envelope(new SampleUseCase()), $stack));
    }

    private function resultOf(Envelope $envelope): Result
    {
        $result = $envelope->last(HandledStamp::class)?->getResult();
        self::assertInstanceOf(Result::class, $result);

        return $result;
    }
}
