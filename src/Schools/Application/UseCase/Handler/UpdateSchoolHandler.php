<?php

declare(strict_types=1);

namespace App\Schools\Application\UseCase\Handler;

use App\Core\Application\Bus\Result;
use App\Core\Application\Bus\UseCase;
use App\Core\Application\Bus\UseCaseHandlerInterface;
use App\Core\Domain\Service\ActivityLog\ActivityLoggerInterface;
use App\Core\Infrastructure\Bus\Attributes\AsUseCaseHandler;
use App\Schools\Application\Service\SchoolDetailsWriter;
use App\Schools\Application\UseCase\Command\UpdateSchoolCommand;
use App\Schools\Domain\Exception\PrincipalBlocksPlanException;
use App\Schools\Domain\Exception\SchoolNotFoundException;
use App\Schools\Domain\Model\Repository\SchoolAssignmentRepositoryInterface;
use App\Schools\Domain\Model\Repository\SchoolRepositoryInterface;
use App\Schools\Domain\Model\Repository\SchoolYearRepositoryInterface;
use App\Schools\Domain\Model\School;

/** @implements UseCaseHandlerInterface<UpdateSchoolCommand> */
#[AsUseCaseHandler(UpdateSchoolCommand::class)]
final class UpdateSchoolHandler implements UseCaseHandlerInterface
{
    public function __construct(
        private readonly SchoolRepositoryInterface $schools,
        private readonly SchoolYearRepositoryInterface $schoolYears,
        private readonly SchoolAssignmentRepositoryInterface $assignments,
        private readonly SchoolDetailsWriter $writer,
        private readonly ActivityLoggerInterface $activityLogger,
    ) {
    }

    /** @param UpdateSchoolCommand $command */
    public function __invoke(UseCase $command): Result
    {
        $school = $this->schools->findById($command->id) ?? throw new SchoolNotFoundException();
        $this->writer->write($school, $command);
        $this->assertPlanFitsPrincipal($school);
        $this->activityLogger->log('Updated school ' . SchoolDetailsWriter::describe($school));

        return Result::success(['name' => $school->getName()]);
    }

    /** A plan without a principal can only be chosen once the school has no principal this school year. */
    private function assertPlanFitsPrincipal(School $school): void
    {
        $plan = $school->getPlan();
        $currentYear = $this->schoolYears->findCurrent();
        if (null === $plan || $plan->includesPrincipal() || null === $currentYear) {
            return;
        }

        if (null !== $this->assignments->findPrincipal($school, $currentYear)) {
            throw new PrincipalBlocksPlanException(\sprintf(
                'Remove the principal of %s for %s before choosing the %s plan.',
                $school->getName(),
                $currentYear->getName(),
                $plan->getName(),
            ));
        }
    }
}
