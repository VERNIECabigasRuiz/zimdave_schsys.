<?php

declare(strict_types=1);

namespace App\Schools\Application\UseCase\Handler;

use App\Core\Application\Bus\Result;
use App\Core\Application\Bus\UseCase;
use App\Core\Application\Bus\UseCaseHandlerInterface;
use App\Core\Domain\Service\ActivityLog\ActivityLoggerInterface;
use App\Core\Infrastructure\Bus\Attributes\AsUseCaseHandler;
use App\Schools\Application\Service\SchoolDetailsWriter;
use App\Schools\Application\UseCase\Command\CreateSchoolCommand;
use App\Schools\Domain\Model\Repository\SchoolRepositoryInterface;
use App\Schools\Domain\Model\School;

/** @implements UseCaseHandlerInterface<CreateSchoolCommand> */
#[AsUseCaseHandler(CreateSchoolCommand::class)]
final class CreateSchoolHandler implements UseCaseHandlerInterface
{
    public function __construct(
        private readonly SchoolRepositoryInterface $schools,
        private readonly SchoolDetailsWriter $writer,
        private readonly ActivityLoggerInterface $activityLogger,
    ) {
    }

    /** @param CreateSchoolCommand $command */
    public function __invoke(UseCase $command): Result
    {
        $school = new School();
        $this->writer->write($school, $command);
        $this->schools->add($school);
        $this->activityLogger->log('Created school ' . SchoolDetailsWriter::describe($school));

        return Result::success(['name' => $school->getName()]);
    }
}
