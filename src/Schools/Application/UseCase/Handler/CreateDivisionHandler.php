<?php

declare(strict_types=1);

namespace App\Schools\Application\UseCase\Handler;

use App\Core\Application\Bus\Result;
use App\Core\Application\Bus\UseCase;
use App\Core\Application\Bus\UseCaseHandlerInterface;
use App\Core\Infrastructure\Bus\Attributes\AsUseCaseHandler;
use App\Schools\Application\Service\DivisionDetailsWriter;
use App\Schools\Application\UseCase\Command\CreateDivisionCommand;
use App\Schools\Domain\Model\Division;

/** @implements UseCaseHandlerInterface<CreateDivisionCommand> */
#[AsUseCaseHandler(CreateDivisionCommand::class)]
final class CreateDivisionHandler implements UseCaseHandlerInterface
{
    public function __construct(private readonly DivisionDetailsWriter $writer)
    {
    }

    /** @param CreateDivisionCommand $command */
    public function __invoke(UseCase $command): Result
    {
        return Result::success($this->writer->write(new Division(), $command, 'Added division %s.'));
    }
}
