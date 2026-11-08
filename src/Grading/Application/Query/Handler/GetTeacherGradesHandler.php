<?php

declare(strict_types=1);

namespace App\Grading\Application\Query\Handler;

use App\Classes\Application\Api\TeachingLoadDirectoryInterface;
use App\Classes\Domain\Model\TeachingLoad;
use App\Core\Application\Bus\Query;
use App\Core\Application\Bus\QueryHandlerInterface;
use App\Core\Application\Bus\Result;
use App\Core\Infrastructure\Bus\Attributes\AsQueryHandler;
use App\Grading\Application\Query\Command\GetTeacherGradesCommand;
use App\Grading\Application\Service\GradeRules;
use App\Grading\Application\Service\GradingScope;
use App\Grading\Application\Service\SheetLearner;
use App\Grading\Domain\Model\Repository\GradeRepositoryInterface;
use App\Schools\Application\Api\SchoolDirectoryInterface;

/** @implements QueryHandlerInterface<GetTeacherGradesCommand> */
#[AsQueryHandler(GetTeacherGradesCommand::class)]
final class GetTeacherGradesHandler implements QueryHandlerInterface
{
    public function __construct(
        private readonly GradingScope $scope,
        private readonly SchoolDirectoryInterface $schools,
        private readonly TeachingLoadDirectoryInterface $loads,
        private readonly GradeRules $rules,
        private readonly GradeRepositoryInterface $grades,
    ) {
    }

    /** @param GetTeacherGradesCommand $command */
    public function __invoke(Query $command): Result
    {
        $teacher = $this->scope->account($command->actorId);
        $schoolYear = $this->schools->currentYear();
        $loads = null === $schoolYear ? [] : $this->loads->teacherLoadsOf($teacher, $schoolYear);
        usort($loads, static fn (TeachingLoad $a, TeachingLoad $b) => self::sortKey($a) <=> self::sortKey($b));

        $periods = null === $schoolYear ? [] : $schoolYear->getGradingPeriods()->getValues();
        $graded = [];
        $ungraded = [];
        foreach ($loads as $load) {
            if (!$this->rules->isGradable($load)) {
                $ungraded[] = $load;
                continue;
            }
            $editable = array_values(array_filter(
                $this->rules->sheet($load->getSection()),
                static fn (SheetLearner $l) => $l->editable,
            ));
            $ids = GradeRules::enrollmentIds($editable);
            $cells = [];
            foreach ($periods as $period) {
                $saved = $this->grades->findForEnrollments($ids, $load->getSubject(), $period);
                $cells[] = [
                    'period' => $period,
                    'graded' => \count(array_filter(
                        $editable,
                        static fn (SheetLearner $l) => isset($saved[(int) $l->enrollment->getId()]),
                    )),
                    'listed' => \count($editable),
                    'status' => $this->rules->status($load->getSection(), $load->getSubject(), $period),
                ];
            }
            $graded[] = ['load' => $load, 'cells' => $cells];
        }

        return Result::success([
            'school_year' => $schoolYear,
            'periods' => $periods,
            'graded' => $graded,
            'ungraded' => $ungraded,
        ]);
    }

    /** @return array{int, string, int, string} grade level, section, subject position, subject */
    private static function sortKey(TeachingLoad $load): array
    {
        $section = $load->getSection();

        return [
            $section->getGradeLevel()->value ?? -1,
            mb_strtoupper($section->getName()),
            $load->getSubject()->getPosition() ?? \PHP_INT_MAX,
            mb_strtoupper($load->getSubject()->getName()),
        ];
    }
}
