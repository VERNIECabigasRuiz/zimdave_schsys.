<?php

declare(strict_types=1);

namespace App\Schools\Application\UseCase\Handler;

use App\Core\Application\Bus\Result;
use App\Core\Application\Bus\UseCase;
use App\Core\Application\Bus\UseCaseHandlerInterface;
use App\Core\Infrastructure\Bus\Attributes\AsUseCaseHandler;
use App\Schools\Application\Service\DivisionDetailsWriter;
use App\Schools\Application\UseCase\Command\UpdateDivisionCommand;
use App\Schools\Domain\Exception\DivisionNotFoundException;
use App\Schools\Domain\Model\Repository\DivisionRepositoryInterface;

/** @implements UseCaseHandlerInterface<UpdateDivisionCommand> */
#[AsUseCaseHandler(UpdateDivisionCommand::class)]
final class UpdateDivisionHandler implements UseCaseHandlerInterface
{
    public function __construct(
        private readonly DivisionRepositoryInterface $divisions,
        private readonly DivisionDetailsWriter $writer,
    ) {
    }

    /** @param UpdateDivisionCommand $command */
    public function __invoke(UseCase $command): Result
    {
        $division = $this->divisions->findById($command->id) ?? throw new DivisionNotFoundException();

        return Result::success($this->writer->write($division, $command, 'Saved division %s.'));
    }
}
