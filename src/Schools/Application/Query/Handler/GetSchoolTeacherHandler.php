<?php

declare(strict_types=1);

namespace App\Schools\Application\Query\Handler;

use App\Core\Application\Bus\Query;
use App\Core\Application\Bus\QueryHandlerInterface;
use App\Core\Application\Bus\Result;
use App\Core\Domain\Service\Clock\SchoolClockInterface;
use App\Core\Infrastructure\Bus\Attributes\AsQueryHandler;
use App\Schools\Application\Query\Command\GetSchoolTeacherCommand;
use App\Schools\Application\Service\SchoolTeacherScope;
use App\Schools\Application\Transformer\SchoolTransformer;
use App\Schools\Application\Transformer\TeacherTransformer;

/** @implements QueryHandlerInterface<GetSchoolTeacherCommand> */
#[AsQueryHandler(GetSchoolTeacherCommand::class)]
final class GetSchoolTeacherHandler implements QueryHandlerInterface
{
    public function __construct(
        private readonly SchoolTeacherScope $scope,
        private readonly SchoolClockInterface $clock,
    ) {
    }

    /** @param GetSchoolTeacherCommand $command */
    public function __invoke(Query $command): Result
    {
        $school = $this->scope->school($command->schoolId);
        $teacher = $this->scope->teacher($school, $this->scope->currentYear(), $command->teacherId);

        return Result::success([
            'school' => SchoolTransformer::toArray($school, $this->clock->now()),
            'teacher' => TeacherTransformer::toArray($teacher),
        ]);
    }
}
