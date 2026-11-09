<?php

declare(strict_types=1);

namespace App\Core\Infrastructure\Bus\Middleware;

use App\Core\Application\Bus\Result;
use App\Core\Domain\Exception\DomainException;
use App\Core\Domain\Exception\GenericErrorCode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Monolog\Attribute\WithMonologChannel;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Exception\ValidationFailedException;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * Turns any exception thrown while handling a message into a failed Result,
 * so callers always get a Result back.
 */
#[WithMonologChannel('bus')]
final class ExceptionHandlingMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly ManagerRegistry $doctrine,
    ) {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        try {
            return $stack->next()->handle($envelope, $stack);
        } catch (\Throwable $exception) {
            // A write the database refused closes the entity manager; give the rest of the request a fresh one
            $manager = $this->doctrine->getManager();
            if ($manager instanceof EntityManagerInterface && !$manager->isOpen()) {
                $this->doctrine->resetManager();
            }

            if ($exception instanceof HandlerFailedException) {
                $exception = array_values($exception->getWrappedExceptions())[0] ?? $exception;
            }

            return $envelope->with(new HandledStamp($this->toResult($exception, $envelope), self::class));
        }
    }

    private function toResult(\Throwable $exception, Envelope $envelope): Result
    {
        if ($exception instanceof DomainException) {
            return Result::failure($exception, $exception->errorCode());
        }

        if ($exception instanceof ValidationFailedException) {
            return Result::failure($exception, GenericErrorCode::ValidationFailed->value);
        }

        $this->logger->error('Unexpected exception handling ' . $envelope->getMessage()::class, [
            'exceptionClass' => $exception::class,
            'message' => $exception->getMessage(),
        ]);

        return Result::failure($exception, GenericErrorCode::InternalError->value);
    }
}
