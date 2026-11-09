<?php

declare(strict_types=1);

namespace App\Classes\Application\Query\Handler;

use App\Access\Application\Api\AccountDirectoryInterface;
use App\Access\Domain\Model\User;
use App\Classes\Application\Query\Command\GetTeacherLoadCommand;
use App\Classes\Application\Service\ClassesScope;
use App\Classes\Application\Service\Timetable\TimetableQuery;
use App\Core\Application\Bus\Query;
use App\Core\Application\Bus\QueryHandlerInterface;
use App\Core\Application\Bus\Result;
use App\Core\Infrastructure\Bus\Attributes\AsQueryHandler;
use App\Schools\Application\Api\SchoolDirectoryInterface;
use App\Schools\Domain\Exception\TeacherNotFoundException;

/** @implements QueryHandlerInterface<GetTeacherLoadCommand> */
#[AsQueryHandler(GetTeacherLoadCommand::class)]
final class GetTeacherLoadHandler implements QueryHandlerInterface
{
    public function __construct(
        private readonly ClassesScope $scope,
        private readonly SchoolDirectoryInterface $schools,
        private readonly AccountDirectoryInterface $accounts,
        private readonly TimetableQuery $timetable,
    ) {
    }

    /** @param GetTeacherLoadCommand $command */
    public function __invoke(Query $command): Result
    {
        $schoolYear = $this->schools->currentYear();
        $teacher = $this->accounts->findById($command->teacherId);
        $school = null === $teacher || null === $schoolYear
            ? null
            : $this->schools->schoolOf($teacher, $schoolYear, User::ROLE_TEACHER);
        $elsewhere = null !== $command->schoolId && $school !== $this->scope->school($command->schoolId);
        if (null === $teacher || $elsewhere) {
            throw new TeacherNotFoundException('This teacher is not in the school this school year.');
        }

        $week = null === $school ? null : $this->timetable->teacherWeek($teacher, $schoolYear, 0);
        $hasSemesters = null !== $week && [] !== array_filter(
            $week['loads'],
            static fn (array $load) => null !== $load['semester'],
        );
        $term = 0;
        if ($hasSemesters) {
            $term = \in_array($command->term, [1, 2], true) ? (int) $command->term : 1;
            $week = $this->timetable->teacherWeek($teacher, $schoolYear, $term);
        }

        return Result::success([
            'teacher' => ['id' => (int) $teacher->getId(), 'fullName' => $teacher->getFullName()],
            'school' => null !== $school ? ['id' => (int) $school->getId(), 'name' => $school->getName()] : null,
            'schoolYear' => null !== $schoolYear ? ['name' => $schoolYear->getName()] : null,
            'week' => $week,
            'term' => $term,
            'hasSemesters' => $hasSemesters,
        ]);
    }
}
