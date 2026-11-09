<?php

declare(strict_types=1);

namespace App\Schools\Application\Query\Handler;

use App\Access\Domain\Model\User;
use App\Core\Application\Bus\Query;
use App\Core\Application\Bus\QueryHandlerInterface;
use App\Core\Application\Bus\Result;
use App\Core\Domain\Service\Clock\SchoolClockInterface;
use App\Core\Infrastructure\Bus\Attributes\AsQueryHandler;
use App\Schools\Application\Query\Command\GetTeacherSchoolCommand;
use App\Schools\Application\Service\SchoolTeacherScope;
use App\Schools\Application\Transformer\SchoolTransformer;
use App\Schools\Application\Transformer\SchoolYearTransformer;
use App\Schools\Application\Transformer\TeacherTransformer;
use App\Schools\Domain\Model\Repository\SchoolAssignmentRepositoryInterface;
use App\Schools\Domain\Model\Repository\SchoolRepositoryInterface;
use App\Schools\Domain\Model\School;

/** @implements QueryHandlerInterface<GetTeacherSchoolCommand> */
#[AsQueryHandler(GetTeacherSchoolCommand::class)]
final class GetTeacherSchoolHandler implements QueryHandlerInterface
{
    public function __construct(
        private readonly SchoolTeacherScope $scope,
        private readonly SchoolRepositoryInterface $schools,
        private readonly SchoolAssignmentRepositoryInterface $assignments,
        private readonly SchoolClockInterface $clock,
    ) {
    }

    /** @param GetTeacherSchoolCommand $command */
    public function __invoke(Query $command): Result
    {
        $account = $this->scope->teacherAccount($command->accountId);
        $schoolYear = $this->scope->currentYear();
        $current = $this->assignments->findSchoolOf($account, $schoolYear, User::ROLE_TEACHER);
        $active = array_filter($this->schools->findAllOrdered(), static fn (School $school) => $school->isActive());

        return Result::success([
            'account' => TeacherTransformer::toArray($account),
            'schoolYear' => SchoolYearTransformer::toArray($schoolYear),
            'current' => null !== $current ? SchoolTransformer::toArray($current, $this->clock->now()) : null,
            'schools' => array_values(array_map(static fn (School $school) => [
                'id' => (int) $school->getId(),
                'label' => $school->getSchoolId() . ' · ' . $school->getName(),
            ], $active)),
        ]);
    }
}
