<?php

declare(strict_types=1);

namespace App\Tests\Core\Unit\Infrastructure\Bus;

use App\Core\Application\Bus\Result;
use App\Core\Infrastructure\Bus\MessengerUseCaseBus;
use App\Tests\Core\Unit\Infrastructure\Bus\Fixture\SampleUseCase;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

final class AbstractMessengerCommandBusTest extends TestCase
{
    public function testReturnsTheHandledResult(): void
    {
        // Initialize
        $handled = Result::success(['ok' => true]);
        $bus = $this->busReturning(new HandledStamp($handled, 'handler'));

        // Execute
        $result = $bus->handle(new SampleUseCase());

        // Assert
        self::assertSame($handled, $result);
    }

    public function testSentWithoutHandlingIsSuccessWithoutData(): void
    {
        // Initialize
        $bus = $this->busReturning();

        // Execute
        $result = $bus->handle(new SampleUseCase());

        // Assert
        self::assertTrue($result->isSuccess());
        self::assertNull($result->data());
    }

    public function testMoreThanOneHandlerIsInternalError(): void
    {
        // Initialize
        $bus = $this->busReturning(
            new HandledStamp(Result::success(), 'first'),
            new HandledStamp(Result::success(), 'second'),
        );

        // Execute
        $result = $bus->handle(new SampleUseCase());

        // Assert
        self::assertTrue($result->isFailure());
        self::assertSame('INTERNAL_ERROR', $result->errorCode());
        self::assertInstanceOf(\LogicException::class, $result->exception());
        self::assertStringContainsString(SampleUseCase::class, $result->exception()->getMessage());
    }

    public function testNonResultIsInternalError(): void
    {
        // Initialize
        $bus = $this->busReturning(new HandledStamp('x', 'h'));

        // Execute
        $result = $bus->handle(new SampleUseCase());

        // Assert
        self::assertTrue($result->isFailure());
        self::assertSame('INTERNAL_ERROR', $result->errorCode());
    }

    private function busReturning(HandledStamp ...$stamps): MessengerUseCaseBus
    {
        $messageBus = self::createStub(MessageBusInterface::class);
        $messageBus->method('dispatch')->willReturn(new Envelope(new SampleUseCase(), $stamps));

        return new MessengerUseCaseBus($messageBus);
    }
}
