<?php

declare(strict_types=1);

namespace App\Core\Infrastructure\Bus;

use App\Core\Application\Bus\CommandBusInterface;
use App\Core\Application\Bus\Query;
use App\Core\Application\Bus\Result;
use App\Core\Application\Bus\UseCase;
use App\Core\Domain\Exception\GenericErrorCode;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

abstract class AbstractMessengerCommandBus implements CommandBusInterface
{
    public function __construct(private MessageBusInterface $bus)
    {
    }

    public function handle(UseCase|Query $command): Result
    {
        $handled = $this->bus->dispatch($command)->all(HandledStamp::class);

        // Sent to a queue instead of being handled now
        if ($handled === []) {
            return Result::success();
        }

        if (count($handled) > 1) {
            return Result::failure(
                new \LogicException(sprintf('More than one handler handled %s.', $command::class)),
                GenericErrorCode::InternalError->value,
            );
        }

        $result = $handled[0]->getResult();

        return $result instanceof Result ? $result : Result::failure(
            new \LogicException(sprintf('The handler of %s did not return a Result.', $command::class)),
            GenericErrorCode::InternalError->value,
        );
    }
}
